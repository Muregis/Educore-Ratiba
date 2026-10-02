<?php
// Temporary redirect note: full file restored via multi-part
// See edit_actions.php for working move/swap API.
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';

$schoolId = requireLoginAndGetSchoolId();
$stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

if (file_exists(__DIR__ . '/generate_engine.php')) {
    require_once __DIR__ . '/generate_engine.php';
}

$stmt = db()->prepare("SELECT * FROM generated_timetables WHERE school_id = ? AND status = 'success' ORDER BY generated_at DESC LIMIT 1");
$stmt->execute([$schoolId]);
$latestGeneration = $stmt->fetch();

$editMode = $_GET['mode'] ?? 'class';
$selectedId = (int)($_GET['selected_id'] ?? 0);
$previewMode = isset($_GET['preview']) && $_GET['preview'] === '1';
$error = null;

$editItems = [];
try {
    if ($editMode === 'teacher') {
        $s = db()->prepare('SELECT * FROM teachers WHERE school_id = ? ORDER BY name');
        $s->execute([$schoolId]); $editItems = $s->fetchAll();
    } elseif ($editMode === 'room') {
        $s = db()->prepare('SELECT * FROM rooms WHERE school_id = ? ORDER BY name');
        $s->execute([$schoolId]); $editItems = $s->fetchAll();
    } elseif ($editMode === 'subject') {
        $s = db()->prepare('SELECT DISTINCT id, name FROM subjects WHERE school_id = ? ORDER BY name');
        $s->execute([$schoolId]); $editItems = $s->fetchAll();
    } else {
        $editMode = 'class';
        $editItems = getClasses($schoolId);
    }
} catch (Throwable $e) { $error = $e->getMessage(); }

$slots = [];
$hourSlotsInUse = [];
if ($latestGeneration && $selectedId !== 0) {
    $map = ['class'=>'class_id','teacher'=>'teacher_id','room'=>'room_id','subject'=>'subject_id'];
    $col = $map[$editMode] ?? 'class_id';
    $stmt = db()->prepare("SELECT scheduled_slots.*, subjects.name AS subject_name, teachers.name AS teacher_name, rooms.name AS room_name, classes.name AS class_name FROM scheduled_slots LEFT JOIN subjects ON scheduled_slots.subject_id = subjects.id LEFT JOIN teachers ON scheduled_slots.teacher_id = teachers.id LEFT JOIN rooms ON scheduled_slots.room_id = rooms.id LEFT JOIN classes ON scheduled_slots.class_id = classes.id WHERE scheduled_slots.generated_timetable_id = ? AND scheduled_slots.$col = ? ORDER BY scheduled_slots.day_of_week, scheduled_slots.hour_slot");
    $stmt->execute([$latestGeneration['id'], $selectedId]);
    $slots = $stmt->fetchAll();
    foreach ($slots as $s) { $hourSlotsInUse[$s['hour_slot']] = true; }
}
$days = getSchoolDayNames($schoolId);
if (count($days) < 1) $days = ['Monday','Tuesday','Wednesday','Thursday','Friday'];
$hourSlotsSorted = array_keys($hourSlotsInUse);
sort($hourSlotsSorted);

$pageTitle = 'Edit Timetable — ' . ($school['name'] ?? '');
require __DIR__ . '/_header.php';

$grid = [];
foreach ($slots as $slot) {
    $grid[$slot['day_of_week']][$slot['hour_slot']] = $slot;
}
?>
<div class="card">
<h2 style="margin-top:0">Edit Timetable</h2>
<p class="empty">Click a lesson, then an empty cell to <strong>move</strong> it. Enable Swap Mode and pick two lessons to <strong>swap</strong>.</p>
<?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if (!$latestGeneration): ?>
<div class="error">Generate a successful timetable first. <a href="generate.php">Generate</a></div>
<?php else: ?>
<div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:12px;">
<div>
<label>Edit by</label>
<select id="edit_mode" onchange="location.href='edit-timetable.php?mode='+this.value">
<option value="class" <?php echo $editMode==='class'?'selected':''; ?>>Class</option>
<option value="teacher" <?php echo $editMode==='teacher'?'selected':''; ?>>Teacher</option>
<option value="room" <?php echo $editMode==='room'?'selected':''; ?>>Room</option>
<option value="subject" <?php echo $editMode==='subject'?'selected':''; ?>>Subject</option>
</select>
</div>
<div>
<label>Select</label>
<select id="selected_item" onchange="location.href='edit-timetable.php?mode=<?php echo urlencode($editMode); ?>&selected_id='+this.value">
<option value="">— select —</option>
<?php foreach ($editItems as $item): ?>
<option value="<?php echo (int)$item['id']; ?>" <?php echo (int)$item['id']===$selectedId?'selected':''; ?>><?php echo htmlspecialchars($item['name']); ?></option>
<?php endforeach; ?>
</select>
</div>
</div>
<?php if ($selectedId === 0): ?>
<p class="empty">Select a <?php echo htmlspecialchars($editMode); ?> to edit.</p>
<?php elseif (!$slots): ?>
<p class="empty">No slots for this selection. <a href="generate.php">Generate</a></p>
<?php else: ?>
<div id="move-feedback"></div>
<div style="margin-bottom:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
<button type="button" class="btn btn-secondary" id="swapBtn" onclick="toggleSwap()">Enable Swap Mode</button>
<span id="swap-status" class="empty">Click a lesson to select it for move</span>
</div>
<div style="overflow-x:auto;">
<table style="width:100%;border-collapse:collapse;min-width:720px;font-size:0.85rem;">
<thead><tr style="background:#1e3a5f;color:#fff;">
<th style="border:1px solid #334155;padding:8px;">Time</th>
<?php foreach ($days as $day): ?><th style="border:1px solid #334155;padding:8px;"><?php echo htmlspecialchars($day); ?></th><?php endforeach; ?>
</tr></thead>
<tbody>
<?php foreach ($hourSlotsSorted as $hour):
  $hourLabel = preg_replace('/^[^_]*__/', '', (string)$hour) ?: (string)$hour;
?>
<tr>
<td style="border:1px solid #cbd5e1;padding:6px;background:#f1f5f9;font-weight:600;white-space:nowrap;"><?php echo htmlspecialchars($hourLabel); ?></td>
<?php foreach ($days as $day):
  $slot = $grid[$day][$hour] ?? null;
?>
<td style="border:1px solid #cbd5e1;padding:4px;vertical-align:top;min-width:120px;">
<?php if ($slot): ?>
<div data-slot-id="<?php echo (int)$slot['id']; ?>" onclick="onSlotClick(<?php echo (int)$slot['id']; ?>)" style="cursor:pointer;padding:6px;border-radius:6px;background:#fff;border:1px solid #e2e8f0;">
<div style="font-weight:700;"><?php echo htmlspecialchars($slot['subject_name'] ?? '—'); ?></div>
<div style="font-size:0.75rem;color:#475569;"><?php echo htmlspecialchars($slot['teacher_name'] ?? '—'); ?></div>
<div style="font-size:0.75rem;color:#1d4ed8;"><?php echo htmlspecialchars($slot['room_name'] ?? ''); ?></div>
<?php if (!empty($slot['is_manual_override'])): ?><span style="font-size:0.7rem;color:#d97706;">Manual</span><?php endif; ?>
</div>
<?php else: ?>
<div onclick="moveSelectedToEmpty(this)" data-day="<?php echo htmlspecialchars($day); ?>" data-hour="<?php echo htmlspecialchars($hour); ?>" style="cursor:pointer;text-align:center;color:#cbd5e1;padding:16px 0;">—</div>
<?php endif; ?>
</td>
<?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; endif; ?>
</div>
<script>
let swapMode = false, selectedSlotId = null, selectedForMove = null;
function toggleSwap(){
  swapMode = !swapMode; selectedSlotId = null;
  document.getElementById('swapBtn').textContent = swapMode ? 'Disable Swap Mode' : 'Enable Swap Mode';
  document.getElementById('swap-status').textContent = swapMode ? 'Select first lesson to swap' : 'Click a lesson to select it for move';
  document.querySelectorAll('[data-slot-id]').forEach(el => el.style.outline = '');
}
function onSlotClick(id){
  if (swapMode) {
    if (!selectedSlotId) {
      selectedSlotId = id;
      document.getElementById('swap-status').textContent = 'Select second lesson to swap';
      const el = document.querySelector('[data-slot-id="'+id+'"]');
      if (el) el.style.outline = '3px solid #4f46e5';
    } else if (selectedSlotId !== id) {
      doSwap(selectedSlotId, id);
    }
    return;
  }
  selectedForMove = id;
  document.querySelectorAll('[data-slot-id]').forEach(el => el.style.outline = '');
  const el = document.querySelector('[data-slot-id="'+id+'"]');
  if (el) el.style.outline = '3px solid #4f46e5';
  document.getElementById('swap-status').textContent = 'Lesson selected — click an empty cell to move';
}
async function doSwap(a,b){
  const fd = new FormData(); fd.append('action','swap_slots'); fd.append('slot_id_1',a); fd.append('slot_id_2',b);
  const res = await fetch('edit_actions.php',{method:'POST',body:fd});
  const data = await res.json();
  const f = document.getElementById('move-feedback');
  if (data.ok){ f.innerHTML='<div class="success">Swapped. Reloading…</div>'; setTimeout(()=>location.reload(),700); }
  else { f.innerHTML='<div class="error">'+(data.error||'Swap failed')+'</div>'; selectedSlotId=null; }
}
async function moveSelectedToEmpty(el){
  const id = selectedForMove || selectedSlotId;
  if (!id){ document.getElementById('move-feedback').innerHTML='<div class="error">Click a lesson first, then an empty cell.</div>'; return; }
  const fd = new FormData(); fd.append('action','move_slot'); fd.append('slot_id',id);
  fd.append('new_day', el.getAttribute('data-day')); fd.append('new_hour_slot', el.getAttribute('data-hour'));
  const res = await fetch('edit_actions.php',{method:'POST',body:fd});
  const data = await res.json();
  const f = document.getElementById('move-feedback');
  if (data.ok){ f.innerHTML='<div class="success">Moved. Reloading…</div>'; setTimeout(()=>location.reload(),700); }
  else f.innerHTML='<div class="error">'+(data.error||'Move failed')+'</div>';
}
</script>
<?php require __DIR__ . '/_footer.php'; ?>
