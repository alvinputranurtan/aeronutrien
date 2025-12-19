<?php
require_once __DIR__.'/db.php';

// Handle global theme update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_global_theme') {
    $theme = in_array($_POST['theme'] ?? '', ['light', 'dark']) ? $_POST['theme'] : 'light';
    $prefs = json_encode(['dark_mode' => $theme]);
    db()->prepare('UPDATE configurations SET preferences = ? WHERE device_id = 0')->execute([$prefs]);
    exit;
}

// Fetch global theme
$global_st = db()->prepare('SELECT preferences FROM configurations WHERE device_id = 0 AND is_active=1');
$global_st->execute();
$global_cfg = $global_st->fetch();
$global_prefs = $global_cfg ? json_decode($global_cfg['preferences'], true) : [];
$global_theme = $global_prefs['dark_mode'] ?? 'light';

$st = db()->query('SELECT d.id, d.device_code, d.name, d.last_seen FROM devices d INNER JOIN configurations c ON d.id = c.device_id WHERE d.deleted_at IS NULL AND c.is_active=1 AND c.deleted_at IS NULL AND JSON_EXTRACT(c.data_configuration, \'$.device_type\') = \'nutrition\' GROUP BY d.id ORDER BY d.id DESC');
$devices = $st->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo $global_theme === 'dark' ? 'dark' : ''; ?>"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Device List - NPK Dashboard</title>
<link href="https://fonts.googleapis.com" rel="preconnect"/>
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
<script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#10b981", // Emerald 500 for NPK/Nature theme
                        primary_hover: "#059669",
                        "background-light": "#f3f4f6",
                        "background-dark": "#0f172a",
                        "surface-light": "#ffffff",
                        "surface-dark": "#1e293b",
                        "border-light": "#e5e7eb",
                        "border-dark": "#334155",
                    },
                    fontFamily: {
                        display: ["Inter", "sans-serif"],
                        body: ["Inter", "sans-serif"],
                    },
                    borderRadius: {
                        DEFAULT: "0.5rem",
                    },
                },
            },
        };
    </script>
<style>
        body {
            font-family: 'Inter', sans-serif;
        }.transition-colors {
            transition-property: background-color, border-color, color, fill, stroke;
            transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
            transition-duration: 200ms;
        }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark text-gray-800 dark:text-gray-200 min-h-screen transition-colors p-6 md:p-10">
<div class="max-w-7xl mx-auto">
<div class="flex flex-col md:flex-row md:items-center justify-between mb-8">
<div>
<h1 class="text-3xl font-bold text-gray-900 dark:text-white tracking-tight">Device List</h1>
<p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Manage your NPK nutrient controller units.</p>
</div>
<div class="mt-4 md:mt-0 flex gap-3">
<div class="relative">
<span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
<span class="material-icons-round text-gray-400 dark:text-gray-500">search</span>
</span>
<input class="pl-10 pr-4 py-2 border border-border-light dark:border-border-dark rounded-lg bg-surface-light dark:bg-surface-dark text-sm focus:ring-2 focus:ring-primary focus:border-primary dark:focus:ring-primary dark:text-white shadow-sm w-full md:w-64" placeholder="Search devices..." type="text"/>
</div>
<button class="flex items-center gap-2 bg-primary hover:bg-primary_hover text-white px-4 py-2 rounded-lg text-sm font-medium shadow-sm transition-colors">
<span class="material-icons-round text-sm">add</span>
                    Add Device
                </button>
<button class="p-2 bg-surface-light dark:bg-surface-dark border border-border-light dark:border-border-dark rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors" onclick="toggleDarkMode()">
<span id="theme-icon" class="material-icons-round">brightness_5</span>
</button>
</div>
</div>
<div class="bg-surface-light dark:bg-surface-dark rounded-xl shadow-lg border border-border-light dark:border-border-dark overflow-hidden">
<div class="overflow-x-auto">
<table class="w-full text-left border-collapse">
<thead>
<tr class="bg-gray-50 dark:bg-slate-800/50 border-b border-border-light dark:border-border-dark">
<th class="px-6 py-4 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider w-16 text-center" scope="col">ID</th>
<th class="px-6 py-4 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider" scope="col">Device Code</th>
<th class="px-6 py-4 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider" scope="col">Name</th>
<th class="px-6 py-4 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider" scope="col">Last Seen</th>
<th class="px-6 py-4 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider" scope="col">Config</th>
<th class="px-6 py-4 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider text-right" scope="col">NPK Dashboard</th>
</tr>
</thead>
<tbody class="divide-y divide-border-light dark:divide-border-dark">
<?php foreach ($devices as $d) { ?>
<tr class="hover:bg-gray-50 dark:hover:bg-slate-800/60 transition-colors group">
<td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium text-gray-900 dark:text-white">
                                <?php echo (int) $d['id']; ?>
                            </td>
<td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300 font-mono">
                                <?php echo htmlspecialchars($d['device_code'] ?? '', ENT_QUOTES); ?>
                            </td>
<td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white font-medium">
                                <?php echo htmlspecialchars($d['name'] ?? '', ENT_QUOTES); ?>
                            </td>
<td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
<?php if (empty($d['last_seen'])) { ?>
<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-400">
                                    Never
                                </span>
<?php } else { ?>
<span class="flex items-center gap-2">
<span class="flex h-2 w-2 relative">
<span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
<span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
</span>
                                <?php echo htmlspecialchars($d['last_seen'], ENT_QUOTES); ?>
                            </span>
<?php } ?>
</td>
<td class="px-6 py-4 whitespace-nowrap text-sm">
<a class="inline-flex items-center gap-1 text-primary hover:text-primary_hover dark:text-primary dark:hover:text-emerald-400 font-medium transition-colors" href="config_init.php?device_id=<?php echo (int) $d['id']; ?>">
<span class="material-icons-round text-base">settings_ethernet</span>
                                    Init/Check
                                </a>
</td>
<td class="px-6 py-4 whitespace-nowrap text-sm text-right">
<a class="inline-flex items-center gap-1 px-3 py-1.5 rounded-md border border-primary text-primary hover:bg-primary hover:text-white dark:border-primary dark:text-primary dark:hover:bg-primary dark:hover:text-white text-xs font-medium transition-all" href="npk_dashboard.php?device_id=<?php echo (int) $d['id']; ?>">
                                    Open
                                    <span class="material-icons-round text-sm">open_in_new</span>
</a>
</td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
<div class="px-6 py-4 border-t border-border-light dark:border-border-dark flex items-center justify-between bg-gray-50 dark:bg-slate-800/30">
<p class="text-sm text-gray-500 dark:text-gray-400">
                    Showing <span class="font-medium">1</span> to <span class="font-medium"><?php echo count($devices); ?></span> of <span class="font-medium"><?php echo count($devices); ?></span> results
                </p>
<div class="flex gap-2">
<button class="px-3 py-1 border border-border-light dark:border-border-dark rounded bg-white dark:bg-surface-dark text-gray-500 dark:text-gray-400 text-sm hover:bg-gray-50 dark:hover:bg-slate-700 disabled:opacity-50" disabled="">
                        Previous
                    </button>
<button class="px-3 py-1 border border-border-light dark:border-border-dark rounded bg-white dark:bg-surface-dark text-gray-500 dark:text-gray-400 text-sm hover:bg-gray-50 dark:hover:bg-slate-700 disabled:opacity-50" disabled="">
                        Next
                    </button>
</div>
</div>
</div>
</div>

<script>
function toggleDarkMode() {
  document.documentElement.classList.toggle('dark');
  const isDark = document.documentElement.classList.contains('dark');
  localStorage.setItem('darkMode', isDark ? 'dark' : 'light');
  document.getElementById('theme-icon').textContent = isDark ? 'brightness_2' : 'brightness_5';
  // Save to DB
  fetch(window.location.href, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'update_global_theme', theme: isDark ? 'dark' : 'light' })
  });
}

// Load initial dark mode
const initialDark = localStorage.getItem('darkMode') === 'dark' || '<?php echo $global_theme; ?>' === 'dark';
if (initialDark) {
  document.documentElement.classList.add('dark');
}
document.getElementById('theme-icon').textContent = initialDark ? 'brightness_2' : 'brightness_5';
</script>

</body></html>
