  <!-- / content -->

  <!-- footer -->
  <footer id="footer" class="app-footer" role="footer">
        <div class="wrapper b-t bg-light">
      <span class="pull-right">Powered by <a href="/" target="_blank"><?php echo $conf['sitename']?></a></span>
    	&copy; 2016-<?php echo date("Y")?> Copyright.
    </div>
  </footer>
  <!-- / footer -->

</div>

<script src="<?php echo $cdnpublic?>jquery/3.4.1/jquery.min.js"></script>
<script src="<?php echo $cdnpublic?>twitter-bootstrap/3.4.1/js/bootstrap.min.js"></script>
<script src="./assets/js/ui-load.js"></script>
<script src="./assets/js/ui-jp.config.js"></script>
<script src="./assets/js/ui-jp.js"></script>
<script src="./assets/js/ui-nav.js"></script>
<script src="./assets/js/ui-toggle.js"></script>
<script>
(function(){
  function bindExpanded(buttonId, targetId, openClass){
    var button=document.getElementById(buttonId), target=document.getElementById(targetId);
    if(!button || !target) return;
    function sync(){ button.setAttribute('aria-expanded', target.classList.contains(openClass) ? 'true' : 'false'); }
    button.addEventListener('click', function(){ window.setTimeout(sync, 0); });
    if(window.MutationObserver) new MutationObserver(sync).observe(target, {attributes:true, attributeFilter:['class']});
    sync();
  }
  bindExpanded('merchant-account-toggle', 'merchant-account-menu', 'show');
  bindExpanded('merchant-nav-toggle', 'merchant-primary-nav', 'off-screen');
})();
</script>
</body>
</html>
