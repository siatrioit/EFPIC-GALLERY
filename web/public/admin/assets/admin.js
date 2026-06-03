(function () {
  var list = document.getElementById('sortable');
  var input = document.getElementById('image_order');
  if (!list || !input) return;

  var dragEl = null;

  function syncOrder() {
    var tokens = [];
    list.querySelectorAll('li[data-token]').forEach(function (li) {
      tokens.push(li.getAttribute('data-token'));
    });
    input.value = tokens.join(',');
  }

  list.querySelectorAll('li').forEach(function (li) {
    li.setAttribute('draggable', 'true');
    li.addEventListener('dragstart', function () {
      dragEl = li;
      li.classList.add('dragging');
    });
    li.addEventListener('dragend', function () {
      li.classList.remove('dragging');
      dragEl = null;
      syncOrder();
    });
    li.addEventListener('dragover', function (e) {
      e.preventDefault();
      if (!dragEl || dragEl === li) return;
      var rect = li.getBoundingClientRect();
      var after = e.clientY > rect.top + rect.height / 2;
      if (after) {
        li.parentNode.insertBefore(dragEl, li.nextSibling);
      } else {
        li.parentNode.insertBefore(dragEl, li);
      }
    });
  });

  syncOrder();
  list.closest('form').addEventListener('submit', syncOrder);
})();
