{{-- B-24: "Add line" for tables marked data-lines. Clones the last row, bumps the [n] index in names, clears values. --}}
<script>
document.querySelectorAll('[data-add-line]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var tbody = document.querySelector(btn.dataset.addLine + ' tbody');
        var last = tbody.rows[tbody.rows.length - 1];
        var next = tbody.rows.length;
        var row = last.cloneNode(true);
        row.querySelectorAll('input, select').forEach(function (el) {
            el.name = el.name.replace(/\[\d+\]/, '[' + next + ']');
            if (el.tagName === 'SELECT') { el.selectedIndex = 0; } else { el.value = ''; }
        });
        tbody.appendChild(row);
        row.querySelector('select, input').focus();
    });
});
</script>
