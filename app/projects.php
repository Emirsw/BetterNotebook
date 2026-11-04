<?php
$pageTitle = "Projeler & Kanban";
require_once __DIR__ . '/includes/auth_check.php';

$pdo = getDBConnection();
$userId = $_SESSION['user_id'];

// Proje Ekleme / Silme
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_project') {
        $title = trim($_POST['title'] ?? '');
        $category = trim($_POST['category'] ?? 'Web Geliştirme');
        $techStack = trim($_POST['tech_stack'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $githubUrl = trim($_POST['github_url'] ?? '');
        $liveUrl = trim($_POST['live_url'] ?? '');
        $status = $_POST['status'] ?? 'in_progress';
        $progress = (int)($_POST['progress_percent'] ?? 0);
        $deadline = !empty($_POST['deadline']) ? $_POST['deadline'] : null;

        if (!empty($title)) {
            $stmt = $pdo->prepare("INSERT INTO projects (user_id, title, category, tech_stack, description, github_url, live_url, status, progress_percent, deadline) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $title, $category, $techStack, $description, $githubUrl, $liveUrl, $status, $progress, $deadline]);
            setFlash('success', 'Yeni proje başarıyla oluşturuldu!');
        } else {
            setFlash('error', 'Proje başlığı zorunludur.');
        }
        redirect('projects.php');
    } elseif ($action === 'delete_project') {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM projects WHERE id = ? AND user_id = ?");
        $stmt->execute([$projectId, $userId]);
        setFlash('success', 'Proje ve tüm görevleri silindi.');
        redirect('projects.php');
    }
}

// Projeleri Getir
$stmt = $pdo->prepare("SELECT * FROM projects WHERE user_id = ? ORDER BY status ASC, created_at DESC");
$stmt->execute([$userId]);
$projects = $stmt->fetchAll();

$selectedProjectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : ($projects[0]['id'] ?? 0);
$selectedProject = null;

foreach ($projects as $p) {
    if ($p['id'] == $selectedProjectId) {
        $selectedProject = $p;
        break;
    }
}

$tasks = ['todo' => [], 'in_progress' => [], 'done' => []];
if ($selectedProject) {
    $stmt = $pdo->prepare("SELECT * FROM project_tasks WHERE project_id = ? ORDER BY id DESC");
    $stmt->execute([$selectedProjectId]);
    $allTasks = $stmt->fetchAll();
    foreach ($allTasks as $t) {
        if (isset($tasks[$t['status']])) {
            $tasks[$t['status']][] = $t;
        }
    }
}

include_once __DIR__ . '/includes/header.php';
?>

<!-- Üst Başlık & Proje Ekle Butonu -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <h2 class="text-xl font-bold text-white">Yazılım Projeleri & Kanban</h2>
        <p class="text-xs text-slate-400 mt-0.5">Yaptığın yazılım projelerini, aşamalarını ve görevlerini organize et.</p>
    </div>
    <button onclick="openModal('modal-add-project')" class="px-4 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold flex items-center gap-1.5 transition shadow-sm">
        <i data-lucide="plus" class="w-4 h-4"></i>
        <span>Yeni Proje Başlat</span>
    </button>
</div>

<!-- Proje Seçim Sekmeleri -->
<?php if (!empty($projects)): ?>
    <div class="flex items-center gap-3 overflow-x-auto pb-2 scrollbar-none">
        <?php foreach ($projects as $p): ?>
            <a href="projects.php?project_id=<?= $p['id'] ?>" class="flex-shrink-0 px-4 py-3 rounded-2xl glass-panel border transition flex items-center gap-3 <?= ($p['id'] == $selectedProjectId) ? 'border-sky-500/60 bg-slate-900/90 shadow-sm' : 'hover:border-slate-700' ?>">
                <div class="w-8 h-8 rounded-xl bg-slate-800 border border-slate-700 flex items-center justify-center text-sky-400 font-bold text-xs">
                    <i data-lucide="code-2" class="w-4 h-4"></i>
                </div>
                <div>
                    <h4 class="text-xs font-bold text-white"><?= e($p['title']) ?></h4>
                    <span class="text-[10px] text-slate-400"><?= e($p['category']) ?> &bull; %<?= $p['progress_percent'] ?></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Seçili Projenin Detayı & Kanban Tahtası -->
<?php if ($selectedProject): ?>
    <div class="space-y-6">
        <!-- Proje Üst Bilgi Kartı -->
        <div class="glass-panel rounded-2xl p-6 relative overflow-hidden">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                <div class="space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-[10px] font-bold px-2.5 py-0.5 rounded-md bg-slate-800 text-slate-300 border border-slate-700"><?= e($selectedProject['category']) ?></span>
                        <span class="text-[10px] font-bold px-2.5 py-0.5 rounded-md bg-emerald-500/15 text-emerald-300 border border-emerald-500/30 uppercase"><?= e($selectedProject['status']) ?></span>
                        <?php if ($selectedProject['deadline']): ?>
                            <span class="text-xs text-slate-400 flex items-center gap-1">
                                <i data-lucide="calendar" class="w-3.5 h-3.5"></i> Teslim: <?= formatTurkishDate($selectedProject['deadline']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <h3 class="text-lg font-bold text-white"><?= e($selectedProject['title']) ?></h3>
                    <p class="text-xs text-slate-400 max-w-3xl leading-relaxed"><?= e($selectedProject['description'] ?: 'Açıklama girilmemiş.') ?></p>
                    
                    <?php if ($selectedProject['tech_stack']): ?>
                        <div class="flex flex-wrap items-center gap-1.5 pt-1">
                            <?php foreach (explode(',', $selectedProject['tech_stack']) as $tech): ?>
                                <span class="text-[10px] px-2 py-0.5 rounded bg-slate-900 text-slate-300 border border-slate-800"><?= trim(e($tech)) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <?php if ($selectedProject['github_url']): ?>
                        <a href="<?= e($selectedProject['github_url']) ?>" target="_blank" class="p-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 transition flex items-center gap-1.5 text-xs font-medium border border-slate-700">
                            <i data-lucide="github" class="w-4 h-4"></i>
                            <span>GitHub</span>
                        </a>
                    <?php endif; ?>

                    <?php if ($selectedProject['live_url']): ?>
                        <a href="<?= e($selectedProject['live_url']) ?>" target="_blank" class="p-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-emerald-400 transition flex items-center gap-1.5 text-xs font-medium border border-slate-700">
                            <i data-lucide="external-link" class="w-4 h-4"></i>
                            <span>Canlı Demo</span>
                        </a>
                    <?php endif; ?>

                    <a href="ai-assistant.php?prompt=<?= urlencode($selectedProject['title'] . ' isimli yazılım projem için mimari yol haritası ve geliştirme adımları hazırla. Teknolojiler: ' . $selectedProject['tech_stack']) ?>" class="p-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-sky-300 transition flex items-center gap-1.5 text-xs font-semibold border border-slate-700">
                        <i data-lucide="sparkles" class="w-4 h-4 text-sky-400"></i>
                        <span>AI Yol Haritası</span>
                    </a>

                    <form method="POST" onsubmit="return confirm('Silmek istediğinize emin misiniz?');" class="inline">
                        <input type="hidden" name="action" value="delete_project">
                        <input type="hidden" name="project_id" value="<?= $selectedProject['id'] ?>">
                        <button type="submit" class="p-2.5 text-slate-500 hover:text-red-400 rounded-xl hover:bg-red-500/10 transition" title="Projeyi Sil">
                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Kanban Sütunları -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            
            <!-- 1. Yapılacaklar (Todo) -->
            <div class="glass-panel rounded-2xl p-4 flex flex-col min-h-[420px]">
                <div class="flex items-center justify-between pb-3 mb-3 border-b border-slate-800">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-slate-400"></span>
                        <h4 class="font-bold text-xs text-slate-300 uppercase tracking-wider">Yapılacaklar</h4>
                    </div>
                    <span class="text-xs px-2 py-0.5 rounded-full bg-slate-800 text-slate-400 font-bold" id="count-todo"><?= count($tasks['todo']) ?></span>
                </div>

                <div id="kanban-todo" data-status="todo" class="kanban-column space-y-2.5 flex-1 min-h-[140px]">
                    <?php foreach ($tasks['todo'] as $task): ?>
                        <div class="kanban-card p-3 rounded-xl bg-slate-900/90 border border-slate-800 cursor-grab active:cursor-grabbing hover:border-slate-700 transition relative group" data-id="<?= $task['id'] ?>">
                            <div class="flex items-start justify-between gap-2">
                                <span class="text-xs text-white font-medium"><?= e($task['title']) ?></span>
                                <button onclick="deleteTask(<?= $task['id'] ?>, this)" class="text-slate-500 hover:text-red-400 opacity-0 group-hover:opacity-100 transition text-xs">✕</button>
                            </div>
                            <div class="mt-2.5 flex items-center justify-between text-[10px]">
                                <?php 
                                    $pColor = ($task['priority'] === 'high') ? 'text-red-300 bg-red-500/10' : (($task['priority'] === 'medium') ? 'text-amber-300 bg-amber-500/10' : 'text-slate-400 bg-slate-800');
                                ?>
                                <span class="px-2 py-0.5 rounded font-semibold uppercase <?= $pColor ?>"><?= $task['priority'] ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="pt-3 border-t border-slate-800 mt-2">
                    <div class="flex gap-1.5">
                        <input type="text" id="new-task-input" placeholder="+ Yeni görev..." class="flex-1 bg-slate-900 border border-slate-800 rounded-xl px-3 py-1.5 text-xs text-white focus:outline-none focus:border-sky-500" onkeydown="if(event.key==='Enter') addNewTask(<?= $selectedProjectId ?>)">
                        <button onclick="addNewTask(<?= $selectedProjectId ?>)" class="px-3 py-1.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-bold">+</button>
                    </div>
                </div>
            </div>

            <!-- 2. Devam Edenler (In Progress) -->
            <div class="glass-panel rounded-2xl p-4 flex flex-col min-h-[420px]">
                <div class="flex items-center justify-between pb-3 mb-3 border-b border-slate-800">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-amber-400"></span>
                        <h4 class="font-bold text-xs text-amber-300 uppercase tracking-wider">Devam Edenler</h4>
                    </div>
                    <span class="text-xs px-2 py-0.5 rounded-full bg-slate-800 text-amber-300 font-bold" id="count-in_progress"><?= count($tasks['in_progress']) ?></span>
                </div>

                <div id="kanban-in_progress" data-status="in_progress" class="kanban-column space-y-2.5 flex-1 min-h-[140px]">
                    <?php foreach ($tasks['in_progress'] as $task): ?>
                        <div class="kanban-card p-3 rounded-xl bg-slate-900/90 border border-slate-800 cursor-grab active:cursor-grabbing hover:border-slate-700 transition relative group" data-id="<?= $task['id'] ?>">
                            <div class="flex items-start justify-between gap-2">
                                <span class="text-xs text-white font-medium"><?= e($task['title']) ?></span>
                                <button onclick="deleteTask(<?= $task['id'] ?>, this)" class="text-slate-500 hover:text-red-400 opacity-0 group-hover:opacity-100 transition text-xs">✕</button>
                            </div>
                            <div class="mt-2.5 flex items-center justify-between text-[10px]">
                                <?php 
                                    $pColor = ($task['priority'] === 'high') ? 'text-red-300 bg-red-500/10' : (($task['priority'] === 'medium') ? 'text-amber-300 bg-amber-500/10' : 'text-slate-400 bg-slate-800');
                                ?>
                                <span class="px-2 py-0.5 rounded font-semibold uppercase <?= $pColor ?>"><?= $task['priority'] ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 3. Tamamlananlar (Done) -->
            <div class="glass-panel rounded-2xl p-4 flex flex-col min-h-[420px]">
                <div class="flex items-center justify-between pb-3 mb-3 border-b border-slate-800">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                        <h4 class="font-bold text-xs text-emerald-300 uppercase tracking-wider">Tamamlananlar</h4>
                    </div>
                    <span class="text-xs px-2 py-0.5 rounded-full bg-slate-800 text-emerald-300 font-bold" id="count-done"><?= count($tasks['done']) ?></span>
                </div>

                <div id="kanban-done" data-status="done" class="kanban-column space-y-2.5 flex-1 min-h-[140px]">
                    <?php foreach ($tasks['done'] as $task): ?>
                        <div class="kanban-card p-3 rounded-xl bg-slate-900/50 border border-slate-800 line-through opacity-70 cursor-grab active:cursor-grabbing hover:border-slate-700 transition relative group" data-id="<?= $task['id'] ?>">
                            <div class="flex items-start justify-between gap-2">
                                <span class="text-xs text-slate-300"><?= e($task['title']) ?></span>
                                <button onclick="deleteTask(<?= $task['id'] ?>, this)" class="text-slate-500 hover:text-red-400 opacity-0 group-hover:opacity-100 transition text-xs">✕</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>
    </div>
<?php else: ?>
    <div class="glass-panel rounded-2xl p-12 text-center max-w-lg mx-auto">
        <div class="w-14 h-14 rounded-2xl bg-slate-800 border border-slate-700 flex items-center justify-center text-sky-400 mx-auto mb-4">
            <i data-lucide="code-2" class="w-7 h-7"></i>
        </div>
        <h3 class="text-base font-bold text-white mb-1">Henüz Proje Eklenmemiş</h3>
        <p class="text-xs text-slate-400 mb-6">Geliştirdiğin projeleri ekleyerek görevlerini organize etmeye başla.</p>
        <button onclick="openModal('modal-add-project')" class="px-5 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-bold shadow-sm">
            + İlk Projeni Başlat
        </button>
    </div>
<?php endif; ?>

<!-- Modal: Yeni Proje Ekle -->
<div id="modal-add-project" class="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="glass-panel rounded-2xl p-6 md:p-8 max-w-lg w-full max-h-[90vh] overflow-y-auto border border-slate-800 shadow-2xl">
        <div class="flex items-center justify-between pb-4 border-b border-slate-800 mb-5">
            <h3 class="text-base font-bold text-white flex items-center gap-2">
                <i data-lucide="folder-plus" class="w-5 h-5 text-sky-400"></i>
                <span>Yeni Proje Oluştur</span>
            </h3>
            <button onclick="closeModal('modal-add-project')" class="text-slate-400 hover:text-white"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="add_project">

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Proje Başlığı *</label>
                <input type="text" name="title" required placeholder="E-Ticaret Platformu / Mobil Uygulama..." class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Kategori</label>
                    <input type="text" name="category" value="Web Geliştirme" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Durum</label>
                    <select name="status" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                        <option value="idea">Fikir</option>
                        <option value="in_progress" selected>Geliştiriliyor</option>
                        <option value="review">Test</option>
                        <option value="completed">Tamamlandı</option>
                        <option value="deployed">Yayında</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Teknolojiler (Virgülle ayırın)</label>
                <input type="text" name="tech_stack" placeholder="PHP, MySQL, JavaScript" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Açıklama</label>
                <textarea name="description" rows="2" placeholder="Projenin amacı..." class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-sky-500"></textarea>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">GitHub Repo URL</label>
                    <input type="url" name="github_url" placeholder="https://github.com/..." class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Canlı Demo URL</label>
                    <input type="url" name="live_url" placeholder="https://..." class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                </div>
            </div>

            <div class="pt-4 border-t border-slate-800 flex justify-end gap-3">
                <button type="button" onclick="closeModal('modal-add-project')" class="px-4 py-2 rounded-xl bg-slate-800 text-slate-300 text-xs font-semibold">İptal</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-bold">Projeyi Oluştur</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const columns = ['kanban-todo', 'kanban-in_progress', 'kanban-done'];
    columns.forEach(colId => {
        const el = document.getElementById(colId);
        if (el) {
            new Sortable(el, {
                group: 'kanban',
                animation: 150,
                ghostClass: 'sortable-ghost',
                chosenClass: 'sortable-chosen',
                onEnd: function (evt) {
                    const taskId = evt.item.getAttribute('data-id');
                    const targetStatus = evt.to.getAttribute('data-status');
                    
                    fetch('api/tasks_api.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'update_status',
                            task_id: taskId,
                            status: targetStatus
                        })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            showToast('success', 'Durum güncellendi.');
                        }
                    });
                }
            });
        }
    });
});

function addNewTask(projectId) {
    const input = document.getElementById('new-task-input');
    const title = input.value.trim();
    if (!title) return;

    fetch('api/tasks_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'add_task',
            project_id: projectId,
            title: title,
            priority: 'medium'
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            input.value = '';
            location.reload();
        }
    });
}

function deleteTask(taskId, btn) {
    if (!confirm('Silmek istiyor musunuz?')) return;

    fetch('api/tasks_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'delete_task',
            task_id: taskId
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            btn.closest('.kanban-card').remove();
            showToast('success', 'Görev silindi.');
        }
    });
}
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
