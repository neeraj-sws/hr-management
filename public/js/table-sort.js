/*
 * Click any column heading of a list (table.table) to sort it: first click ascending, next descending.
 * Works on every list without per-page code: numbers, money, dates ("22 Oct 2026", "2026-10-22",
 * "12-02-2024"), times and durations ("08:30:00") sort by value, anything else alphabetically.
 * A heading with no text (the actions column), or a table with data-no-sort, is left alone.
 * The order is kept when Livewire refreshes the list (e.g. after a filter or an action).
 */
(function () {
  const MONTHS = { jan: 0, feb: 1, mar: 2, apr: 3, may: 4, jun: 5, jul: 6, aug: 7, sep: 8, oct: 9, nov: 10, dec: 11 };
  const state = new Map(); // table key -> { col, dir }

  function key(table) {
    if (!table.dataset.sortKey) {
      const all = Array.from(document.querySelectorAll('table.table'));
      table.dataset.sortKey = location.pathname + '#' + all.indexOf(table);
    }
    return table.dataset.sortKey;
  }

  /** A comparable value for a cell: [kind, value] with kind 0 = empty, 1 = number, 2 = text. */
  function value(cell) {
    if (!cell) return [0, ''];
    if (cell.dataset.sort !== undefined) {
      const n = Number(cell.dataset.sort);
      return isNaN(n) ? [2, cell.dataset.sort.toLowerCase()] : [1, n];
    }
    const text = cell.innerText.trim().replace(/\s+/g, ' ');
    if (!text || text === '—' || text === '-') return [0, ''];
    let m;
    // Duration / clock time 08:30:00 or 8:30 (optionally with AM/PM)
    if ((m = text.match(/^[−-]?\s*(\d{1,3}):(\d{2})(?::(\d{2}))?\s*(am|pm)?\b/i))) {
      let h = Number(m[1]);
      if (m[4]) { h = h % 12; if (m[4].toLowerCase() === 'pm') h += 12; }
      return [1, h * 3600 + Number(m[2]) * 60 + Number(m[3] || 0)];
    }
    // 2026-10-22
    if ((m = text.match(/^(\d{4})-(\d{2})-(\d{2})/))) return [1, Date.UTC(+m[1], +m[2] - 1, +m[3])];
    // 22-10-2026 or 22/10/2026
    if ((m = text.match(/^(\d{1,2})[-/](\d{1,2})[-/](\d{4})/))) return [1, Date.UTC(+m[3], +m[2] - 1, +m[1])];
    // 22 Oct 2026 / Thu, 22 Oct / Oct 2026 / Aug 2026
    if ((m = text.match(/^(?:[A-Za-z]{3},?\s+)?(\d{1,2})\s+([A-Za-z]{3})[a-z]*\.?(?:\s+(\d{4}))?/)) && MONTHS[m[2].toLowerCase()] !== undefined) {
      return [1, Date.UTC(m[3] ? +m[3] : new Date().getFullYear(), MONTHS[m[2].toLowerCase()], +m[1])];
    }
    if ((m = text.match(/^([A-Za-z]{3})[a-z]*\s+(\d{4})$/)) && MONTHS[m[1].toLowerCase()] !== undefined) return [1, Date.UTC(+m[2], MONTHS[m[1].toLowerCase()], 1)];
    // Numbers and money: 1,458.33 / ₹ 500 / +5,000.00 / 47% / 130.00 / day
    const num = text.replace(/[₹$,%+\s]/g, '').replace(/^−/, '-').match(/^-?\d+(\.\d+)?/);
    if (num && /^[₹$+−\-\d\s,.%]/.test(text)) return [1, parseFloat(num[0])];
    return [2, text.toLowerCase()];
  }

  function compare(a, b) {
    if (a[0] === 0 || b[0] === 0) return a[0] === b[0] ? 0 : a[0] === 0 ? 1 : -1; // empty cells last
    if (a[0] !== b[0]) return a[0] - b[0];
    return a[0] === 1 ? a[1] - b[1] : a[1].localeCompare(b[1], undefined, { numeric: true });
  }

  function apply(table) {
    const s = state.get(key(table));
    const body = table.tBodies[0];
    if (!s || !body) return;
    const rows = Array.from(body.rows);
    if (rows.some((r) => r.cells.length === 1 && r.cells[0].colSpan > 1)) return; // "No records" row
    const sorted = rows
      .map((row, i) => ({ row, i, v: value(row.cells[s.col]) }))
      .sort((x, y) => {
        const c = compare(x.v, y.v);
        const empty = x.v[0] === 0 || y.v[0] === 0;
        return (c === 0 ? x.i - y.i : empty ? c : s.dir * c);
      })
      .map((x) => x.row);
    if (sorted.every((row, i) => row === rows[i])) { mark(table, s); return; }
    sorted.forEach((row) => body.appendChild(row));
    mark(table, s);
  }

  function mark(table, s) {
    const head = table.tHead && table.tHead.rows[0];
    if (!head) return;
    Array.from(head.cells).forEach((th, i) => {
      th.classList.toggle('sorted-asc', !!s && i === s.col && s.dir === 1);
      th.classList.toggle('sorted-desc', !!s && i === s.col && s.dir === -1);
      th.setAttribute('aria-sort', s && i === s.col ? (s.dir === 1 ? 'ascending' : 'descending') : 'none');
    });
  }

  function sortable(th) {
    const table = th.closest('table.table');
    return table && !table.hasAttribute('data-no-sort') && th.parentElement === (table.tHead && table.tHead.rows[0]) && th.innerText.trim() !== '' && !th.hasAttribute('data-no-sort');
  }

  document.addEventListener('click', (event) => {
    const th = event.target.closest('th');
    if (!th || !sortable(th) || event.target.closest('a, button, input, select, label')) return;
    const table = th.closest('table.table');
    const col = Array.from(th.parentElement.cells).indexOf(th);
    const current = state.get(key(table));
    state.set(key(table), { col, dir: current && current.col === col ? -current.dir : 1 });
    apply(table);
  });

  // Mark headings as clickable (also for lists that appear later).
  function decorate() {
    document.querySelectorAll('table.table thead th').forEach((th) => { if (sortable(th)) th.classList.add('sortable'); });
  }
  document.addEventListener('DOMContentLoaded', decorate);

  // Livewire redraws the list in server order: put the chosen order back.
  function hook() {
    window.Livewire.hook('commit', ({ succeed }) => succeed(() => setTimeout(() => {
      decorate();
      document.querySelectorAll('table.table').forEach(apply);
    }, 0)));
  }
  if (window.Livewire && window.Livewire.hook) hook();
  else document.addEventListener('livewire:init', hook);
  document.addEventListener('livewire:navigated', () => { state.clear(); decorate(); });
})();
