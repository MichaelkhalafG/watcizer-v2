{{--
    Session/CSRF keep-alive (Issue 5). Pings the admin keep-alive route every
    ~5 min so a long-open form's session can't idle-expire (which would turn the
    next submit into a 419/redirect and lose work). Refreshes the CSRF token
    everywhere it lives, defensively, in case the session was regenerated.
--}}
<script>
(function () {
  var URL = @json(route('keep_alive'));
  setInterval(function () {
    fetch(URL, {
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (d && d.csrf) {
          var m = document.querySelector('meta[name=csrf-token]');
          if (m) m.content = d.csrf;
          document.querySelectorAll('input[name=_token]').forEach(function (i) { i.value = d.csrf; });
        }
      })
      .catch(function () {});
  }, 5 * 60 * 1000);
})();
</script>
