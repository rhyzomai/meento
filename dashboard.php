<?php
// ==========================================
// 1. CONFIGURATION
// ==========================================
$db_host = '127.0.0.1';
$db_user = 'root';
$db_pass = '';
$db_name = 'file_indexer';

$files = [];
$history_data = [
    'day'   => array_fill(0, 24, 0),
    'week'  => array_fill(0, 7, 0),
    'month' => array_fill(0, 30, 0),
    'year'  => array_fill(0, 12, 0)
];
$error_message = '';

// ==========================================
// 2. DATABASE CONNECTION & QUERIES
// ==========================================
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // --- A. FETCH REGISTRY RECORDS ---
    $stmt = $pdo->query("SELECT file_hash, original_filename, file_size, extension, hosts, indexed_at FROM file_registry");
    $all_files = $stmt->fetchAll();

    foreach ($all_files as $key => $file) {
        $hosts_raw = trim($file['hosts']);
        $host_count = 0;

        if (!empty($hosts_raw)) {
            if (strpos($hosts_raw, '[') === 0) {
                $decoded = json_decode($hosts_raw, true);
                $host_count = is_array($decoded) ? count($decoded) : 0;
            } else {
                $host_array = array_filter(explode(',', $hosts_raw));
                $host_count = count($host_array);
            }
        }
        $all_files[$key]['host_count'] = $host_count;
        unset($all_files[$key]['hosts']); 
    }

    usort($all_files, function($a, $b) {
        return $b['host_count'] <=> $a['host_count'];
    });
    $files = array_slice($all_files, 0, 1000);

    // --- B. REAL HISTORICAL AGGREGATIONS (Files Added) ---
    
    // 1. Last 24 Hours (Grouped by Hour offset)
    $day_stmt = $pdo->query("
        SELECT HOUR(TIMEDIFF(NOW(), indexed_at)) as hour_ago, COUNT(*) as qty 
        FROM file_registry 
        WHERE indexed_at >= NOW() - INTERVAL 1 DAY 
        GROUP BY hour_ago
    ");
    foreach ($day_stmt->fetchAll() as $row) {
        $idx = 23 - (int)$row['hour_ago'];
        if ($idx >= 0 && $idx < 24) $history_data['day'][$idx] = (int)$row['qty'];
    }

    // 2. Last 7 Days (Grouped by Day offset)
    $week_stmt = $pdo->query("
        SELECT DATEDIFF(NOW(), indexed_at) as days_ago, COUNT(*) as qty 
        FROM file_registry 
        WHERE indexed_at >= NOW() - INTERVAL 7 DAY 
        GROUP BY days_ago
    ");
    foreach ($week_stmt->fetchAll() as $row) {
        $idx = 6 - (int)$row['days_ago'];
        if ($idx >= 0 && $idx < 7) $history_data['week'][$idx] = (int)$row['qty'];
    }

    // 3. Last 30 Days (Grouped by Day offset)
    $month_stmt = $pdo->query("
        SELECT DATEDIFF(NOW(), indexed_at) as days_ago, COUNT(*) as qty 
        FROM file_registry 
        WHERE indexed_at >= NOW() - INTERVAL 30 DAY 
        GROUP BY days_ago
    ");
    foreach ($month_stmt->fetchAll() as $row) {
        $idx = 29 - (int)$row['days_ago'];
        if ($idx >= 0 && $idx < 30) $history_data['month'][$idx] = (int)$row['qty'];
    }

    // 4. Last 12 Months (Grouped by Month offset)
    $year_stmt = $pdo->query("
        SELECT PERIOD_DIFF(EXTRACT(YEAR_MONTH FROM NOW()), EXTRACT(YEAR_MONTH FROM indexed_at)) as months_ago, COUNT(*) as qty 
        FROM file_registry 
        WHERE indexed_at >= NOW() - INTERVAL 1 YEAR 
        GROUP BY months_ago
    ");
    foreach ($year_stmt->fetchAll() as $row) {
        $idx = 11 - (int)$row['months_ago'];
        if ($idx >= 0 && $idx < 12) $history_data['year'][$idx] = (int)$row['qty'];
    }

} catch (PDOException $e) {
    $error_message = "Database Connection/Query Error: " . htmlspecialchars($e->getMessage());
}

function formatBytes($bytes, $precision = 2) { 
    $units = array('B', 'KB', 'MB', 'GB', 'TB'); 
    $bytes = max($bytes, 0); 
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024)); 
    $pow = min($pow, count($units) - 1); 
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow]; 
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Host Variance Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --bg-color: #f4f7f6;
            --card-bg: #ffffff;
            --text-main: #333333;
            --text-muted: #666666;
            --border-color: #e0e0e0;
            --positive: #2ecc71;
            --negative: #e74c3c;
            --primary: #36a2eb;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            margin: 0;
            padding: 20px;
        }

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border-color);
        }

        .header-titles h1 {
            margin: 0 0 5px 0;
            color: #2c3e50;
            font-size: 24px;
        }

        .header-titles p {
            margin: 0;
            color: var(--text-muted);
            font-size: 14px;
        }

        .controls select {
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            font-size: 14px;
            background-color: white;
            cursor: pointer;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .card {
            background-color: var(--card-bg);
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid var(--border-color);
        }

        .card h3 {
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 16px;
            color: #2c3e50;
        }

        .chart-container-line {
            position: relative;
            height: 350px;
            width: 100%;
        }

        .chart-container-pie {
            position: relative;
            height: 300px;
            width: 100%;
            display: flex;
            justify-content: center;
        }

        .table-responsive {
            overflow-x: auto;
            max-height: 600px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 14px;
        }

        th, td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-color);
        }

        th {
            background-color: #f9fbfb;
            color: var(--text-muted);
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .key-id {
            font-family: monospace;
            font-weight: bold;
            color: var(--text-main);
        }

        .indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 8px;
        }

        .badge {
            background-color: #e3f2fd;
            color: #1565c0;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 13px;
        }

        .error-banner {
            background-color: #ffebee;
            color: #c62828;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border: 1px solid #ffcdd2;
        }

        @media (max-width: 1024px) {
            .dashboard-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <div class="dashboard-header">
        <div class="header-titles">
            <h1>Advanced File Network Dashboard</h1>
            <p>Displaying network registry metadata and growth analysis tracking logs.</p>
        </div>
        <div class="controls">
            <label for="timeframe" style="font-weight: 500; margin-right: 10px;">Network Volatility History:</label>
            <select id="timeframe" onchange="handleTimeframeChange()">
                <option value="day">Last 24 Hours (per Hour)</option>
                <option value="week">Last 7 Days (per Day)</option>
                <option value="month" selected>Last 30 Days (per Day)</option>
                <option value="year">Last 12 Months (per Month)</option>
            </select>
        </div>
    </div>

    <?php if ($error_message): ?>
        <div class="error-banner">
            <strong>Error:</strong> <?= $error_message ?>
        </div>
    <?php endif; ?>

    <div class="dashboard-grid">
        <div class="card">
            <h3>Overall Network Volume (Total Files Indexed)</h3>
            <div class="chart-container-line">
                <canvas id="lineChart"></canvas>
            </div>
        </div>
        <div class="card">
            <h3>Host Distribution (Top 10 Files)</h3>
            <div class="chart-container-pie">
                <canvas id="pieChart"></canvas>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top: 20px;">
        <h3>Performance Metrics (Sorted by Current Hosts - Max 1000)</h3>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>File Hash</th>
                        <th>Original Filename</th>
                        <th>File Size</th>
                        <th>Extension</th>
                        <th>Total Hosts</th>
                        <th>Last Indexed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($files) && empty($error_message)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-muted);">No records found in file_registry.</td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $chart_colors = ['#ff6384', '#36a2eb', '#4bc0c0', '#ff9f40', '#9966ff', '#ffcd56', '#c9cbcf', '#2ecc71', '#e74c3c', '#34495e'];
                        foreach ($files as $index => $file): 
                            $color = $chart_colors[$index % count($chart_colors)];
                            $is_top_10 = $index < 10;
                        ?>
                            <tr>
                                <td>
                                    <?php if($is_top_10): ?>
                                        <span class="indicator" style="background-color: <?= $color ?>;"></span>
                                    <?php else: ?>
                                        <span class="indicator" style="background-color: var(--border-color);"></span>
                                    <?php endif; ?>
                                    <span class="key-id"><?= htmlspecialchars(substr($file['file_hash'], 0, 16)) ?>...</span>
                                </td>
                                <td><?= htmlspecialchars($file['original_filename']) ?></td>
                                <td><?= formatBytes($file['file_size']) ?></td>
                                <td style="text-transform: uppercase;"><?= htmlspecialchars($file['extension']) ?></td>
                                <td><span class="badge"><?= $file['host_count'] ?> Hosts</span></td>
                                <td style="color: var(--text-muted); font-size: 13px;">
                                    <?= htmlspecialchars(date('M j, Y H:i', strtotime($file['indexed_at']))) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        const rawDbData = <?= json_encode($files) ?>;
        const realHistoryMetrics = <?= json_encode($history_data) ?>;
        
        let lineChartInstance = null;
        let pieChartInstance = null;

        const colors = ['#ff6384', '#36a2eb', '#4bc0c0', '#ff9f40', '#9966ff', '#ffcd56', '#c9cbcf', '#2ecc71', '#e74c3c', '#34495e'];

        function updateCharts() {
            const timeframe = document.getElementById('timeframe').value;
            
            // --- Render Real Line Chart ---
            const labelsMap = {
                'day': Array.from({length: 24}, (_, i) => `${24-i}h ago`),
                'week': ['6 Days Ago', '5 Days Ago', '4 Days Ago', '3 Days Ago', '2 Days Ago', 'Yesterday', 'Today'],
                'month': Array.from({length: 30}, (_, i) => `Day -${30-i}`),
                'year': ['11 Months Ago', '10 Months Ago', '9 Months Ago', '8 Months Ago', '7 Months Ago', '6 Months Ago', '5 Months Ago', '4 Months Ago', '3 Months Ago', '2 Months Ago', 'Last Month', 'This Month']
            };

            const lineDatasets = [{
                label: 'Files Added to Infrastructure',
                data: realHistoryMetrics[timeframe],
                borderColor: '#36a2eb',
                backgroundColor: 'rgba(54, 162, 235, 0.1)',
                borderWidth: 3,
                tension: 0.2,
                fill: true,
                pointRadius: 3
            }];

            if (lineChartInstance) lineChartInstance.destroy();
            const ctxLine = document.getElementById('lineChart').getContext('2d');
            lineChartInstance = new Chart(ctxLine, {
                type: 'line',
                data: {
                    labels: labelsMap[timeframe],
                    datasets: lineDatasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { position: 'top' } },
                    scales: {
                        y: { beginAtZero: true, ticks: { precision: 0 } }
                    }
                }
            });

            // --- Render Pie (Doughnut) Chart ---
            const topFiles = rawDbData.slice(0, 10);
            const pieData = topFiles.map(item => parseInt(item.host_count));
            const pieColors = topFiles.map((_, index) => colors[index % colors.length]);
            const pieLabels = topFiles.map(item => item.file_hash.substring(0, 12) + '...');

            if (pieChartInstance) pieChartInstance.destroy();
            const ctxPie = document.getElementById('pieChart').getContext('2d');
            pieChartInstance = new Chart(ctxPie, {
                type: 'doughnut',
                data: {
                    labels: pieLabels,
                    datasets: [{
                        data: pieData,
                        backgroundColor: pieColors,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'right' } }
                }
            });
        }

        function handleTimeframeChange() {
            updateCharts();
        }

        window.onload = () => {
            updateCharts();
        };
    </script>
</body>
</html>