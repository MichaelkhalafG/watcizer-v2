{{--
    Reusable localStorage autosave for long admin forms (Issue 5 — data-loss
    protection). Include once per form:

        @include('Dashboard.partials.form_autosave', [
            'draftKey' => 'product-create',        // per-form key, e.g. product-edit:{id}
            'formId'   => 'product-form',
        ])

    It captures ALL controlled fields — text, numbers, prices, textareas,
    single/multi <select> (incl. Select2), checkboxes, radios — debounced to
    localStorage, and offers a "Restore draft" banner on load. The draft is
    cleared ONLY on a confirmed successful submit (detected on the redirect
    target, see product/index.blade.php), so a WAF "Forbidden" page followed by
    Back/refresh still restores everything. File inputs cannot be serialized, so
    images are re-picked — every typed/selected field survives.
--}}
@php $__formId = $formId ?? 'product-form'; @endphp
<script>
(function () {
  var DRAFT_KEY   = @json($draftKey);
  var FORM_ID     = @json($__formId);
  var HAS_OLD     = {{ count(old()) ? 'true' : 'false' }};
  var SUBMIT_MARK = 'wz_draft_submitting';
  var SKIP_TYPES  = { file: 1, password: 1, submit: 1, button: 1, reset: 1 };

  document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById(FORM_ID);
    if (!form) return;

    // Back on the form page => any previous submit did NOT reach the success
    // redirect (validation bounce or WAF "Forbidden" + Back). Drop the pending
    // marker so the draft is kept, not cleared.
    try { sessionStorage.removeItem(SUBMIT_MARK); } catch (e) {}

    function fields() {
      return Array.prototype.filter.call(form.elements, function (el) {
        if (!el.name) return false;
        if (SKIP_TYPES[el.type]) return false;
        if (el.name === '_token' || el.name === '_method') return false;
        return true;
      });
    }

    function collect() {
      var data = {};
      fields().forEach(function (el) {
        // Skip disabled controls. The product create form renders each category
        // block as a <fieldset disabled> and only enables the active one, so many
        // field names (warranty_years, band_material_id, case_shape_id, country[…],
        // …) are repeated across the hidden blocks. Capturing the disabled copies
        // would let an empty hidden-block value overwrite the value the user typed
        // in the visible block (last-write-wins), losing it on restore. Only the
        // active block's (enabled) values are the real draft.
        //
        // NOTE: el.disabled reflects only the control's OWN disabled attribute, NOT
        // an ancestor <fieldset disabled>. The :disabled pseudo-class (and an
        // explicit disabled-fieldset ancestor check) is what actually catches
        // fieldset-disabled controls — matching what the browser omits on submit.
        if (el.disabled) return;
        if (el.matches && el.matches(':disabled')) return;
        if (el.closest && el.closest('fieldset[disabled], fieldset:disabled')) return;
        var n = el.name;
        if (el.type === 'checkbox') {
          if (!Array.isArray(data[n])) data[n] = [];
          if (el.checked) data[n].push(el.value);
        } else if (el.type === 'radio') {
          if (el.checked) data[n] = el.value;
          else if (!(n in data)) data[n] = null;
        } else if (el.multiple) {
          data[n] = Array.prototype.filter.call(el.options, function (o) { return o.selected; })
                                    .map(function (o) { return o.value; });
        } else {
          data[n] = el.value;
        }
      });
      return data;
    }

    function apply(data) {
      var byName = {};
      fields().forEach(function (el) { (byName[el.name] = byName[el.name] || []).push(el); });
      Object.keys(data).forEach(function (n) {
        var els = byName[n]; if (!els) return;               // field no longer exists → skip
        var val = data[n], first = els[0];
        if (first.type === 'checkbox') {
          var set = Array.isArray(val) ? val : (val == null ? [] : [val]);
          els.forEach(function (el) { el.checked = set.indexOf(el.value) !== -1; });
        } else if (first.type === 'radio') {
          els.forEach(function (el) { el.checked = (el.value === val); });
        } else if (first.multiple) {
          var arr = Array.isArray(val) ? val : (val == null ? [] : [val]);
          Array.prototype.forEach.call(first.options, function (o) { o.selected = arr.indexOf(o.value) !== -1; });
          if (window.jQuery) jQuery(first).trigger('change');   // sync Select2 UI
        } else {
          first.value = (val == null ? '' : val);
          if (window.jQuery && first.classList.contains('select2')) jQuery(first).trigger('change');
          else first.dispatchEvent(new Event('input', { bubbles: true }));
          first.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });
    }

    // ── Debounced save on any edit ──────────────────────────────────────────
    var t = null;
    function save() {
      try { localStorage.setItem(DRAFT_KEY, JSON.stringify({ t: Date.now(), data: collect() })); } catch (e) {}
    }
    form.addEventListener('input',  function () { clearTimeout(t); t = setTimeout(save, 800); }, true);
    form.addEventListener('change', function () { clearTimeout(t); t = setTimeout(save, 800); }, true);

    // ── Restore banner (skipped right after a server validation re-render,
    //    where Laravel already refilled the form via old()) ──────────────────
    var raw = null;
    try { raw = localStorage.getItem(DRAFT_KEY); } catch (e) {}
    if (raw && !HAS_OLD) {
      var parsed = null; try { parsed = JSON.parse(raw); } catch (e) {}
      if (parsed && parsed.data) {
        var when = parsed.t ? new Date(parsed.t).toLocaleString() : '';
        var bar = document.createElement('div');
        bar.className = 'alert alert-warning d-flex align-items-center justify-content-between flex-wrap';
        bar.style.cssText = 'margin:0 0 12px;';
        bar.innerHTML =
          '<span><i class="bi bi-arrow-counterclockwise me-1"></i>' +
          'You have an unsaved draft' + (when ? (' from ' + when) : '') + '.</span>' +
          '<span class="mt-1 mt-sm-0">' +
          '<button type="button" class="btn btn-sm btn-primary me-2" id="wz-draft-restore">Restore draft</button>' +
          '<button type="button" class="btn btn-sm btn-outline-secondary" id="wz-draft-discard">Discard</button>' +
          '</span>';
        form.parentNode.insertBefore(bar, form);
        bar.querySelector('#wz-draft-restore').addEventListener('click', function () { apply(parsed.data); bar.remove(); });
        bar.querySelector('#wz-draft-discard').addEventListener('click', function () {
          try { localStorage.removeItem(DRAFT_KEY); } catch (e) {}
          bar.remove();
        });
      }
    }

    // On submit, mark which draft is being submitted so the success page can
    // clear it. Do NOT clear here — a failed submit must keep the draft.
    form.addEventListener('submit', function () {
      try { sessionStorage.setItem(SUBMIT_MARK, JSON.stringify({ k: DRAFT_KEY, t: Date.now() })); } catch (e) {}
    });
  });
})();
</script>
