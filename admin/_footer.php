</main>
<footer style="text-align:center;padding:20px;color:var(--text-muted);font-size:0.8rem;margin-left:var(--sidebar-w,0);">
    EduCore Ratiba &copy; <?php echo date('Y'); ?>
</footer>
<style>
@media (max-width: 900px) {
  footer { margin-left: 0 !important; }
}
</style>
<?php if (function_exists('getCsrfToken')): ?>
<script>
(function(){
  var t = <?php echo json_encode(getCsrfToken()); ?>;
  document.querySelectorAll('form[method="post"], form[method="POST"]').forEach(function(f){
    if (!f.querySelector('input[name="_csrf_token"]')) {
      var i = document.createElement('input');
      i.type = 'hidden'; i.name = '_csrf_token'; i.value = t;
      f.appendChild(i);
    }
  });
})();
</script>
<?php endif; ?>
</body>
</html>
