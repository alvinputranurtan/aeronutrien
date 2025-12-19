<?php
require_once __DIR__.'/db.php';

$device_id = (int) ($_GET['device_id'] ?? 0);
if ($device_id <= 0) {
    http_response_code(400);
    exit('device_id invalid');
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function clamp_int($v, $min, $max): int
{
    $v = (int) $v;
    if ($v < $min) {
        return $min;
    }
    if ($v > $max) {
        return $max;
    }

    return $v;
}

/** 1) Pastikan device ada */
$st = db()->prepare('SELECT id, name, device_code FROM devices WHERE id=? AND deleted_at IS NULL');
$st->execute([$device_id]);
$device = $st->fetch();
if (!$device) {
    http_response_code(404);
    exit('Device tidak ditemukan.');
}

/** 2) Ambil konfigurasi aktif */
$st = db()->prepare('
    SELECT id, device_id, version, is_active, data_configuration
    FROM configurations
    WHERE device_id=? AND is_active=1 AND deleted_at IS NULL
    ORDER BY version DESC
    LIMIT 1
');
$st->execute([$device_id]);
$cfg = $st->fetch();

/* kalau belum ada config aktif, buat default lewat config_init.php */
if (!$cfg) {
    header('Location: config_init.php?device_id='.$device_id);
    exit;
}

/** 3) Parse JSON config */
$data = json_decode($cfg['data_configuration'], true);
if (!is_array($data)) {
    $data = [];
}

/*
 * ====== COMPAT LAMA ======
 * Kalau data lama masih simpan current/threshold di root, pindahkan ke device_configuration.
 */
if ((!isset($data['device_configuration']) || !is_array($data['device_configuration']))
    && (isset($data['current']) || isset($data['threshold']))) {
    $data['device_configuration'] = [
        'current' => is_array($data['current'] ?? null) ? $data['current'] : ['n' => 0, 'p' => 0, 'k' => 0],
        'threshold' => is_array($data['threshold'] ?? null) ? $data['threshold'] : ['n' => 0, 'p' => 0, 'k' => 0],
    ];
    unset($data['current'], $data['threshold']);
}

$theme = $data['theme'] ?? 'light';
$device_type = $data['device_type'] ?? 'nutrition';

$dc = $data['device_configuration'] ?? [];
if (!is_array($dc)) {
    $dc = [];
}

$current = $dc['current'] ?? ['n' => 0, 'p' => 0, 'k' => 0];
$threshold = $dc['threshold'] ?? ['n' => 0, 'p' => 0, 'k' => 0];

$current = [
    'n' => (int) ($current['n'] ?? 0),
    'p' => (int) ($current['p'] ?? 0),
    'k' => (int) ($current['k'] ?? 0),
];
$threshold = [
    'n' => (int) ($threshold['n'] ?? 0),
    'p' => (int) ($threshold['p'] ?? 0),
    'k' => (int) ($threshold['k'] ?? 0),
];

// Calculate pump status: pump ON when current < threshold
$pumps = [
    'n' => $current['n'] < $threshold['n'],
    'p' => $current['p'] < $threshold['p'],
    'k' => $current['k'] < $threshold['k'],
];

$err = '';
$success = '';

/* 4) Handle update POST (tanpa csrf) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!in_array($action, ['update_current', 'update_threshold', 'update_theme'], true)) {
        $err = 'Action tidak valid.';
    } else {
        if ($action === 'update_theme') {
            $data['theme'] = in_array($_POST['theme'] ?? '', ['light', 'dark'], true) ? $_POST['theme'] : 'light';
        }

        if ($action === 'update_current') {
            $current['n'] = clamp_int($_POST['current_n'] ?? $current['n'], 0, 5000);
            $current['p'] = clamp_int($_POST['current_p'] ?? $current['p'], 0, 5000);
            $current['k'] = clamp_int($_POST['current_k'] ?? $current['k'], 0, 5000);
        }

        if ($action === 'update_threshold') {
            $threshold['n'] = clamp_int($_POST['threshold_n'] ?? $threshold['n'], 0, 5000);
            $threshold['p'] = clamp_int($_POST['threshold_p'] ?? $threshold['p'], 0, 5000);
            $threshold['k'] = clamp_int($_POST['threshold_k'] ?? $threshold['k'], 0, 5000);
        }

        // Pastikan device_configuration selalu ada, dan simpan current/threshold/pumps ke wrapper itu
        if ($action === 'update_current' || $action === 'update_threshold' || $action === 'update_pumps') {
            if (!isset($data['device_configuration']) || !is_array($data['device_configuration'])) {
                $data['device_configuration'] = [];
            }
            $data['device_configuration']['current'] = $current;
            $data['device_configuration']['threshold'] = $threshold;
        }

        // Pastikan device_type ada
        if (!isset($data['device_type']) || !$data['device_type']) {
            $data['device_type'] = 'nutrition';
        }

        try {
            $json = json_encode($data, JSON_UNESCAPED_SLASHES);
            $up = db()->prepare('UPDATE configurations SET data_configuration=? WHERE id=? AND device_id=?');
            $up->execute([$json, $cfg['id'], $device_id]);

            $success = 'Berhasil disimpan.';
            $cfg['data_configuration'] = $json;

            // Refresh theme/current/threshold/pumps setelah save
            $theme = $data['theme'] ?? $theme;
        } catch (PDOException $e) {
            $err = 'Gagal update database.';
        }
    }
}
?>
<!DOCTYPE html>
<html class="<?php echo $theme === 'dark' ? 'dark' : ''; ?>" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>IoT NPK Nutrient Controller Dashboard</title>

<link href="https://fonts.googleapis.com" rel="preconnect"/>
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;700;900&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>

<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<script id="tailwind-config">
tailwind.config = {
  darkMode: "class",
  theme: {
    extend: {
      colors: {
        "primary": "#ea2a33",
        "background-light": "#f8f6f6",
        "background-dark": "#211111",
        "agriculture": "#4caf50",
        "surface-light": "#ffffff",
        "surface-dark": "#1e293b",
        "border-light": "#e5e7eb",
        "border-dark": "#334155",
      },
      fontFamily: { "display": ["Be Vietnam Pro", "sans-serif"] },
      borderRadius: {"DEFAULT":"0.25rem","lg":"0.5rem","xl":"0.75rem","full":"9999px"},
    },
  },
}
</script>
</head>

<body class="bg-background-light dark:bg-background-dark text-[#181111] dark:text-white font-display min-h-screen flex flex-col overflow-x-hidden">
<header class="bg-white dark:bg-[#2a2a2a] border-b border-gray-200 dark:border-gray-800 shadow-sm sticky top-0 z-10">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-5">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div class="flex flex-col gap-1">
        <h1 class="text-[#181111] dark:text-white text-2xl md:text-3xl font-black leading-tight tracking-[-0.033em]">
          IoT NPK Nutrient Controller
        </h1>
        <p class="text-[#886364] dark:text-gray-400 text-sm md:text-base font-normal leading-normal">
          Monitor and control N, P, K nutrient levels in ppm
        </p>
      </div>

      <div class="flex items-center gap-3">
        <!-- THEME TOGGLE: Sun/Moon SVG (CDN) -->
        <button
          type="button"
          class="p-2 bg-surface-light dark:bg-surface-dark border border-border-light dark:border-border-dark rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors flex items-center justify-center"
          onclick="toggleDarkMode()"
          title="Toggle theme"
        >
          <img id="theme-icon" class="h-6 w-6" alt="Theme"/>
        </button>

        <div class="flex items-center gap-2 px-3 py-1.5 bg-green-50 dark:bg-green-900/20 rounded-full border border-green-100 dark:border-green-800">
          <span class="relative flex h-2.5 w-2.5">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-agriculture opacity-75"></span>
            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-agriculture"></span>
          </span>
          <span class="text-xs font-medium text-green-800 dark:text-green-400">System Active</span>
        </div>

        <a href="index.php" class="text-sm font-medium text-primary hover:text-red-700 transition-colors">Back</a>
      </div>
    </div>

    <?php if ($err) { ?>
      <div class="mt-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 border border-red-200 dark:border-red-800 text-sm"><?php echo h($err); ?></div>
    <?php } ?>
    <?php if ($success) { ?>
      <div class="mt-4 p-3 rounded-lg bg-green-50 dark:bg-green-900/20 text-green-800 dark:text-green-400 border border-green-200 dark:border-green-800 text-sm"><?php echo h($success); ?></div>
      <script>
        // Update pump status after successful save
        setTimeout(function() {
          updatePumpStatus(); // Use PHP values that are now updated
        }, 100);
      </script>
    <?php } ?>
  </div>
</header>

<main class="flex-1 w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    <!-- CURRENT LEVELS -->
    <div class="bg-white dark:bg-[#2a2a2a] rounded-xl shadow-[0_2px_8px_rgba(0,0,0,0.04)] dark:shadow-none border border-gray-100 dark:border-gray-800 flex flex-col h-full">
      <div class="p-6 border-b border-gray-100 dark:border-gray-800 flex justify-between items-center">
        <div class="flex items-center gap-3">
          <div class="p-2 bg-green-50 dark:bg-green-900/20 rounded-lg text-agriculture">
            <span class="material-symbols-outlined">bar_chart</span>
          </div>
          <div>
            <h2 class="text-lg font-bold text-[#181111] dark:text-white">Current NPK Levels</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400">Adjust simulation levels</p>
          </div>
        </div>
        <span class="material-symbols-outlined text-gray-400">edit</span>
      </div>

      <form class="p-6 flex flex-col gap-8 flex-1" method="post">
        <input type="hidden" name="action" value="update_current">

        <div class="flex flex-col gap-3 group">
          <div class="flex justify-between items-end">
            <div class="flex items-center gap-2">
              <span class="flex items-center justify-center w-6 h-6 rounded bg-green-100 text-green-700 text-xs font-bold">N</span>
              <label class="font-medium text-[#181111] dark:text-gray-200 cursor-pointer" for="current-n">Nitrogen</label>
            </div>
            <span class="text-xl font-bold text-[#181111] dark:text-white">
              <span id="current-n-text"><?php echo (int) $current['n']; ?></span>
              <span class="text-sm font-normal text-gray-500">ppm</span>
            </span>
          </div>
          <div class="relative flex items-center h-6">
            <input class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer dark:bg-gray-700 accent-agriculture focus:outline-none focus:ring-2 focus:ring-agriculture/50"
              id="current-n" name="current_n" max="5000" min="0" type="range" value="<?php echo (int) $current['n']; ?>"/>
          </div>
        </div>

        <div class="flex flex-col gap-3 group">
          <div class="flex justify-between items-end">
            <div class="flex items-center gap-2">
              <span class="flex items-center justify-center w-6 h-6 rounded bg-orange-100 text-orange-700 text-xs font-bold">P</span>
              <label class="font-medium text-[#181111] dark:text-gray-200 cursor-pointer" for="current-p">Phosphorus</label>
            </div>
            <span class="text-xl font-bold text-[#181111] dark:text-white">
              <span id="current-p-text"><?php echo (int) $current['p']; ?></span>
              <span class="text-sm font-normal text-gray-500">ppm</span>
            </span>
          </div>
          <div class="relative flex items-center h-6">
            <input class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer dark:bg-gray-700 accent-agriculture focus:outline-none focus:ring-2 focus:ring-agriculture/50"
              id="current-p" name="current_p" max="5000" min="0" type="range" value="<?php echo (int) $current['p']; ?>"/>
          </div>
        </div>

        <div class="flex flex-col gap-3 group">
          <div class="flex justify-between items-end">
            <div class="flex items-center gap-2">
              <span class="flex items-center justify-center w-6 h-6 rounded bg-purple-100 text-purple-700 text-xs font-bold">K</span>
              <label class="font-medium text-[#181111] dark:text-gray-200 cursor-pointer" for="current-k">Potassium</label>
            </div>
            <span class="text-xl font-bold text-[#181111] dark:text-white">
              <span id="current-k-text"><?php echo (int) $current['k']; ?></span>
              <span class="text-sm font-normal text-gray-500">ppm</span>
            </span>
          </div>
          <div class="relative flex items-center h-6">
            <input class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer dark:bg-gray-700 accent-agriculture focus:outline-none focus:ring-2 focus:ring-agriculture/50"
              id="current-k" name="current_k" max="5000" min="0" type="range" value="<?php echo (int) $current['k']; ?>"/>
          </div>
        </div>

        <div class="mt-2 flex justify-end">
          <button class="bg-agriculture hover:bg-green-600 text-white font-bold py-3 px-6 rounded-lg transition-all shadow-md hover:shadow-lg active:scale-95 flex items-center gap-2" type="submit">
            <span class="material-symbols-outlined text-[20px]">save</span>
            Update Levels
          </button>
        </div>
      </form>
    </div>

    <!-- THRESHOLD SETTINGS -->
    <div class="bg-white dark:bg-[#2a2a2a] rounded-xl shadow-[0_2px_8px_rgba(0,0,0,0.04)] dark:shadow-none border border-gray-100 dark:border-gray-800 flex flex-col h-full">
      <div class="p-6 border-b border-gray-100 dark:border-gray-800 flex justify-between items-center">
        <div class="flex items-center gap-3">
          <div class="p-2 bg-primary/10 rounded-lg text-primary">
            <span class="material-symbols-outlined">tune</span>
          </div>
          <div>
            <h2 class="text-lg font-bold text-[#181111] dark:text-white">Threshold Settings</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400">Adjust target values</p>
          </div>
        </div>
        <button type="button" id="btn-reset" class="text-sm font-medium text-primary hover:text-red-700 transition-colors">Reset</button>
      </div>

      <form class="p-6 flex flex-col gap-8 flex-1" method="post">
        <input type="hidden" name="action" value="update_threshold">

        <div class="flex flex-col gap-3 group">
          <div class="flex justify-between items-center">
            <label class="font-medium text-[#181111] dark:text-gray-200" for="threshold-n">Threshold N</label>
            <span class="bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded text-sm font-mono text-[#181111] dark:text-white font-bold">
              <span id="threshold-n-text"><?php echo (int) $threshold['n']; ?></span> ppm
            </span>
          </div>
          <div class="relative flex items-center h-6">
            <input class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer dark:bg-gray-700 accent-primary focus:outline-none focus:ring-2 focus:ring-primary/50"
              id="threshold-n" name="threshold_n" max="5000" min="0" type="range" value="<?php echo (int) $threshold['n']; ?>"/>
          </div>
        </div>

        <div class="flex flex-col gap-3 group">
          <div class="flex justify-between items-center">
            <label class="font-medium text-[#181111] dark:text-gray-200" for="threshold-p">Threshold P</label>
            <span class="bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded text-sm font-mono text-[#181111] dark:text-white font-bold">
              <span id="threshold-p-text"><?php echo (int) $threshold['p']; ?></span> ppm
            </span>
          </div>
          <div class="relative flex items-center h-6">
            <input class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer dark:bg-gray-700 accent-primary focus:outline-none focus:ring-2 focus:ring-primary/50"
              id="threshold-p" name="threshold_p" max="5000" min="0" type="range" value="<?php echo (int) $threshold['p']; ?>"/>
          </div>
        </div>

        <div class="flex flex-col gap-3 group">
          <div class="flex justify-between items-center">
            <label class="font-medium text-[#181111] dark:text-gray-200" for="threshold-k">Threshold K</label>
            <span class="bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded text-sm font-mono text-[#181111] dark:text-white font-bold">
              <span id="threshold-k-text"><?php echo (int) $threshold['k']; ?></span> ppm
            </span>
          </div>
          <div class="relative flex items-center h-6">
            <input class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer dark:bg-gray-700 accent-primary focus:outline-none focus:ring-2 focus:ring-primary/50"
              id="threshold-k" name="threshold_k" max="5000" min="0" type="range" value="<?php echo (int) $threshold['k']; ?>"/>
          </div>
        </div>

        <div class="mt-2 flex justify-end">
          <button class="bg-primary hover:bg-red-600 text-white font-bold py-3 px-6 rounded-lg transition-all shadow-md hover:shadow-lg active:scale-95 flex items-center gap-2" type="submit">
            <span class="material-symbols-outlined text-[20px]">save</span>
            Update Settings
          </button>
        </div>
      </form>
    </div>

    <!-- PUMP STATUS -->
    <div class="bg-white dark:bg-[#2a2a2a] rounded-xl shadow-[0_2px_8px_rgba(0,0,0,0.04)] dark:shadow-none border border-gray-100 dark:border-gray-800 lg:col-span-2 flex flex-col">
      <div class="p-6 border-b border-gray-100 dark:border-gray-800 flex justify-between items-center">
        <div class="flex items-center gap-3">
          <div class="p-2 bg-blue-50 dark:bg-blue-900/20 rounded-lg text-blue-600">
            <span class="material-symbols-outlined">water_drop</span>
          </div>
          <div>
            <h2 class="text-lg font-bold text-[#181111] dark:text-white">Pump Status</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400">Automatic irrigation controls (ON when current < threshold)</p>
          </div>
        </div>
      </div>
      <div class="p-6 grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="flex items-center justify-between p-4 rounded-lg border border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 <?php echo $pumps['n'] ? '' : 'opacity-75'; ?>">
          <div class="flex items-center gap-4">
            <div class="h-10 w-10 rounded-full bg-white dark:bg-gray-700 flex items-center justify-center shadow-sm">
              <span class="font-bold text-gray-700 dark:text-gray-300">N</span>
            </div>
            <div class="flex flex-col">
              <span class="font-bold text-[#181111] dark:text-white">Pump N</span>
              <span class="text-xs text-gray-500">Nitrogen Feed</span>
            </div>
          </div>
          <span class="px-3 py-1 rounded-full text-xs font-bold pump-status <?php echo $pumps['n'] ? 'bg-agriculture/10 text-agriculture border border-agriculture/20' : 'bg-gray-200 text-gray-500 border border-gray-300 dark:bg-gray-700 dark:text-gray-400 dark:border-gray-600'; ?> flex items-center gap-1">
            <span class="w-1.5 h-1.5 rounded-full <?php echo $pumps['n'] ? 'bg-agriculture' : 'bg-gray-400'; ?>"></span>
            <?php echo $pumps['n'] ? 'ON' : 'OFF'; ?>
          </span>
        </div>
        <div class="flex items-center justify-between p-4 rounded-lg border border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 <?php echo $pumps['p'] ? '' : 'opacity-75'; ?>">
          <div class="flex items-center gap-4">
            <div class="h-10 w-10 rounded-full bg-white dark:bg-gray-700 flex items-center justify-center shadow-sm">
              <span class="font-bold text-gray-700 dark:text-gray-300">P</span>
            </div>
            <div class="flex flex-col">
              <span class="font-bold text-[#181111] dark:text-white">Pump P</span>
              <span class="text-xs text-gray-500">Phosphorus Feed</span>
            </div>
          </div>
          <span class="px-3 py-1 rounded-full text-xs font-bold pump-status <?php echo $pumps['p'] ? 'bg-agriculture/10 text-agriculture border border-agriculture/20' : 'bg-gray-200 text-gray-500 border border-gray-300 dark:bg-gray-700 dark:text-gray-400 dark:border-gray-600'; ?> flex items-center gap-1">
            <span class="w-1.5 h-1.5 rounded-full <?php echo $pumps['p'] ? 'bg-agriculture' : 'bg-gray-400'; ?>"></span>
            <?php echo $pumps['p'] ? 'ON' : 'OFF'; ?>
          </span>
        </div>
        <div class="flex items-center justify-between p-4 rounded-lg border border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 <?php echo $pumps['k'] ? '' : 'opacity-75'; ?>">
          <div class="flex items-center gap-4">
            <div class="h-10 w-10 rounded-full bg-white dark:bg-gray-700 flex items-center justify-center shadow-sm">
              <span class="font-bold text-gray-700 dark:text-gray-300">K</span>
            </div>
            <div class="flex flex-col">
              <span class="font-bold text-[#181111] dark:text-white">Pump K</span>
              <span class="text-xs text-gray-500">Potassium Feed</span>
            </div>
          </div>
          <span class="px-3 py-1 rounded-full text-xs font-bold pump-status <?php echo $pumps['k'] ? 'bg-agriculture/10 text-agriculture border border-agriculture/20' : 'bg-gray-200 text-gray-500 border border-gray-300 dark:bg-gray-700 dark:text-gray-400 dark:border-gray-600'; ?> flex items-center gap-1">
            <span class="w-1.5 h-1.5 rounded-full <?php echo $pumps['k'] ? 'bg-agriculture' : 'bg-gray-400'; ?>"></span>
            <?php echo $pumps['k'] ? 'ON' : 'OFF'; ?>
          </span>
        </div>
      </div>
    </div>

    <!-- SYSTEM OPTIMIZATION -->
    <div class="bg-primary rounded-xl shadow-lg p-6 lg:col-span-2 text-white relative overflow-hidden group">
      <div class="relative z-10 flex flex-col md:flex-row justify-between items-center gap-4">
        <div>
          <h3 class="text-xl font-bold mb-1">System Optimization</h3>
          <p class="text-white/80 text-sm max-w-md">The AI controller has optimized nutrient delivery for maximum yield based on current sensor readings.</p>
        </div>
        <button class="bg-white text-primary px-4 py-2 rounded-lg font-bold text-sm hover:bg-gray-100 transition shadow-sm">View Report</button>
      </div>
      <div class="absolute top-0 right-0 w-64 h-64 bg-white/10 rounded-full blur-3xl -mr-16 -mt-16 pointer-events-none"></div>
      <div class="absolute bottom-0 left-0 w-48 h-48 bg-black/10 rounded-full blur-2xl -ml-10 -mb-10 pointer-events-none"></div>
    </div>

  </div>
</main>

<script>
console.log('NPK Dashboard JavaScript loaded');

function bindRange(rangeId, textId) {
  const r = document.getElementById(rangeId);
  const t = document.getElementById(textId);
  if (!r || !t) return;
  const update = () => { t.textContent = r.value; };
  r.addEventListener('input', update);
  update();
}

bindRange('current-n', 'current-n-text');
bindRange('current-p', 'current-p-text');
bindRange('current-k', 'current-k-text');

bindRange('threshold-n', 'threshold-n-text');
bindRange('threshold-p', 'threshold-p-text');
bindRange('threshold-k', 'threshold-k-text');

document.getElementById('btn-reset')?.addEventListener('click', () => {
  const defaults = { n: 45, p: 55, k: 75 };
  document.getElementById('threshold-n').value = defaults.n;
  document.getElementById('threshold-p').value = defaults.p;
  document.getElementById('threshold-k').value = defaults.k;
  bindRange('threshold-n', 'threshold-n-text');
  bindRange('threshold-p', 'threshold-p-text');
  bindRange('threshold-k', 'threshold-k-text');
});

/* ===== Theme toggle: Sun/Moon SVG CDN ===== */
const ICON_SUN  = 'https://unpkg.com/heroicons@2.1.1/24/solid/sun.svg';
const ICON_MOON = 'https://unpkg.com/heroicons@2.1.1/24/solid/moon.svg';

function setThemeIcon(isDark) {
  const img = document.getElementById('theme-icon');
  if (!img) return;
  img.src = isDark ? ICON_MOON : ICON_SUN;
}

function toggleDarkMode() {
  document.documentElement.classList.toggle('dark');
  const isDark = document.documentElement.classList.contains('dark');

  localStorage.setItem('darkMode', isDark ? 'dark' : 'light');
  setThemeIcon(isDark);

  // Save to DB (theme disimpan di data_configuration)
  fetch(window.location.href, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'update_theme', theme: isDark ? 'dark' : 'light' })
  });
}

// Load initial dark mode
const initialDark =
  localStorage.getItem('darkMode') === 'dark' ||
  '<?php echo $theme; ?>' === 'dark';

if (initialDark) document.documentElement.classList.add('dark');
setThemeIcon(initialDark);

/* ===== Pump status display (automatic based on current vs threshold) ===== */
function updatePumpStatus() {
  // Use PHP values that are already updated after save
  const currentN = <?php echo $current['n']; ?>;
  const currentP = <?php echo $current['p']; ?>;
  const currentK = <?php echo $current['k']; ?>;

  const thresholdN = <?php echo $threshold['n']; ?>;
  const thresholdP = <?php echo $threshold['p']; ?>;
  const thresholdK = <?php echo $threshold['k']; ?>;

  // Calculate pump status: ON when current < threshold
  const pumpN = currentN < thresholdN;
  const pumpP = currentP < thresholdP;
  const pumpK = currentK < thresholdK;

  // Update UI for each pump
  updatePumpUI('n', pumpN);
  updatePumpUI('p', pumpP);
  updatePumpUI('k', pumpK);

  console.log(`Pump status updated - N: ${pumpN ? 'ON' : 'OFF'}, P: ${pumpP ? 'ON' : 'OFF'}, K: ${pumpK ? 'ON' : 'OFF'}`);
}

function updatePumpUI(pumpType, isOn) {
  const pumpCard = document.querySelector(`[data-pump="${pumpType}"]`);
  if (!pumpCard) return;

  const statusBadge = pumpCard.querySelector('.pump-status');
  if (statusBadge) {
    statusBadge.className = `px-3 py-1 rounded-full text-xs font-bold flex items-center gap-1 pump-status ${
      isOn
        ? 'bg-agriculture/10 text-agriculture border border-agriculture/20'
        : 'bg-gray-200 text-gray-500 border border-gray-300 dark:bg-gray-700 dark:text-gray-400 dark:border-gray-600'
    }`;
    statusBadge.innerHTML = `<span class="w-1.5 h-1.5 rounded-full ${isOn ? 'bg-agriculture' : 'bg-gray-400'}"></span> ${isOn ? 'ON' : 'OFF'}`;
  }

  pumpCard.classList.toggle('opacity-75', !isOn);
}

// Update pump status when sliders change (real-time preview)
function updatePumpStatusFromInputs() {
  // Get current values from inputs for real-time preview
  const currentN = parseInt(document.getElementById('current-n')?.value) || 0;
  const currentP = parseInt(document.getElementById('current-p')?.value) || 0;
  const currentK = parseInt(document.getElementById('current-k')?.value) || 0;

  const thresholdN = parseInt(document.getElementById('threshold-n')?.value) || 0;
  const thresholdP = parseInt(document.getElementById('threshold-p')?.value) || 0;
  const thresholdK = parseInt(document.getElementById('threshold-k')?.value) || 0;

  // Calculate pump status: ON when current < threshold
  const pumpN = currentN < thresholdN;
  const pumpP = currentP < thresholdP;
  const pumpK = currentK < thresholdK;

  // Update UI for each pump
  updatePumpUI('n', pumpN);
  updatePumpUI('p', pumpP);
  updatePumpUI('k', pumpK);

  console.log(`Pump status preview - N: ${pumpN ? 'ON' : 'OFF'}, P: ${pumpP ? 'ON' : 'OFF'}, K: ${pumpK ? 'ON' : 'OFF'}`);
}

// Update pump status when sliders change
document.addEventListener('DOMContentLoaded', function() {
  console.log('DOM loaded, setting up pump status monitoring');

  // Listen for input changes on current and threshold sliders
  const sliders = ['current-n', 'current-p', 'current-k', 'threshold-n', 'threshold-p', 'threshold-k'];
  sliders.forEach(id => {
    const slider = document.getElementById(id);
    if (slider) {
      slider.addEventListener('input', updatePumpStatusFromInputs);
      slider.addEventListener('change', updatePumpStatusFromInputs);
    }
  });

  // Initial update using PHP values
  updatePumpStatus();
});
</script>

</body>
</html>