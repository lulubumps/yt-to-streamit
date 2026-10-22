/*
 * YouTube a Streamit — panel de administración.
 * Sin dependencias. Hace cuatro cosas:
 *   1) Selector de programa con buscador (.yts-combo), alimentado por window.YTS.shows = [{id, title}].
 *   2) Paginación y filtro de tablas (table.yts-paginated). Todas las filas siguen en el formulario aunque estén ocultas.
 *   3) Tabla de programas: agregar, quitar y activar/desactivar filas.
 *   4) Videos de la playlist: botones Marcar todos / Desmarcar todos.
 */
(function () {
	'use strict';

	var each = function (list, fn) { Array.prototype.forEach.call(list, fn); };

	// Minúsculas y sin acentos, para que "camara" encuentre "Cámara"
	function norm(s) {
		s = String(s == null ? '' : s).toLowerCase();
		return s.normalize ? s.normalize('NFD').replace(/[\u0300-\u036f]/g, '') : s;
	}

	/* ---------------- 1) Selector con buscador ---------------- */
	var shows = (window.YTS && window.YTS.shows) || [];
	var MAX_ITEMS = 50;

	function titleOf(id) {
		for (var i = 0; i < shows.length; i++) {
			if (String(shows[i].id) === String(id)) return shows[i].title;
		}
		return '';
	}

	function initCombo(box) {
		if (!box || box.getAttribute('data-ready')) return;
		box.setAttribute('data-ready', '1');

		var input = box.querySelector('.yts-combo-input');
		var hidden = box.querySelector('input[type=hidden]');
		var list = box.querySelector('.yts-combo-list');
		var matches = [];
		var active = -1;
		var typing = false;

		function render() {
			var q = typing ? norm(input.value) : '';
			var all = shows.filter(function (sh) {
				return !q || norm(sh.title).indexOf(q) !== -1 || String(sh.id) === q;
			});
			matches = all.slice(0, MAX_ITEMS);
			active = -1;
			list.innerHTML = '';

			if (!matches.length) {
				var empty = document.createElement('div');
				empty.className = 'yts-combo-empty';
				empty.textContent = shows.length ? 'Sin resultados' : 'No hay programas en Streamit todavía';
				list.appendChild(empty);
			}
			matches.forEach(function (sh) {
				var d = document.createElement('div');
				d.className = 'yts-combo-item' + (String(sh.id) === hidden.value ? ' is-selected' : '');
				d.textContent = sh.title + '  (#' + sh.id + ')';
				d.addEventListener('mousedown', function (e) { e.preventDefault(); pick(sh); });
				list.appendChild(d);
			});
			if (all.length > MAX_ITEMS) {
				var note = document.createElement('div');
				note.className = 'yts-combo-note';
				note.textContent = 'Mostrando ' + MAX_ITEMS + ' de ' + all.length + '. Sigue escribiendo para filtrar.';
				list.appendChild(note);
			}
			list.hidden = false;
		}

		function pick(sh) {
			hidden.value = sh.id;
			input.value = sh.title;
			typing = false;
			list.hidden = true;
		}

		function move(dir) {
			var items = list.querySelectorAll('.yts-combo-item');
			if (!items.length) return;
			active = (active + dir + items.length) % items.length;
			each(items, function (el, i) { el.classList.toggle('is-active', i === active); });
			if (items[active].scrollIntoView) items[active].scrollIntoView({ block: 'nearest' });
		}

		input.addEventListener('focus', function () { typing = false; input.select(); render(); });
		input.addEventListener('input', function () { typing = true; render(); });
		input.addEventListener('blur', function () {
			list.hidden = true;
			input.value = titleOf(hidden.value); // si no eligió nada nuevo, vuelve al programa elegido
		});
		input.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowDown') { e.preventDefault(); if (list.hidden) render(); move(1); }
			else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
			else if (e.key === 'Enter') {
				if (!list.hidden) {
					e.preventDefault(); // no enviar el formulario
					if (active >= 0 && matches[active]) pick(matches[active]);
					else if (matches.length === 1) pick(matches[0]);
				}
			} else if (e.key === 'Escape') { list.hidden = true; input.value = titleOf(hidden.value); }
		});
	}

	/* ---------------- 2) Paginación y filtro ---------------- */
	function initPager(table) {
		var body = table.tBodies[0];
		if (!body) return;
		var per = parseInt(table.getAttribute('data-per-page') || '10', 10);
		var filterSel = table.getAttribute('data-filter');
		var filterEl = filterSel ? document.querySelector(filterSel) : null;
		var page = 1;

		var pager = document.createElement('div');
		pager.className = 'yts-pager';
		table.parentNode.insertBefore(pager, table.nextSibling);

		function rows() { return Array.prototype.slice.call(body.rows); }

		function matchesFilter(tr) {
			if (!filterEl || !filterEl.value) return true;
			var text = tr.textContent;
			each(tr.querySelectorAll('input[type=text]'), function (inp) { text += ' ' + inp.value; });
			return norm(text).indexOf(norm(filterEl.value)) !== -1;
		}

		function button(label, title, enabled, fn) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'button';
			b.textContent = label;
			b.title = title;
			b.disabled = !enabled;
			b.addEventListener('click', fn);
			return b;
		}

		function draw(total, pages) {
			pager.innerHTML = '';
			var filtering = !!(filterEl && filterEl.value);
			if (total <= per && !filtering) return; // nada que paginar

			var info = document.createElement('span');
			info.className = 'displaying-num';
			info.textContent = total + (total === 1 ? ' elemento' : ' elementos');
			pager.appendChild(info);

			if (pages > 1) {
				var nav = document.createElement('span');
				nav.className = 'yts-pager-links';
				nav.appendChild(button('«', 'Primera página', page > 1, function () { page = 1; refresh(); }));
				nav.appendChild(button('‹', 'Anterior', page > 1, function () { page--; refresh(); }));
				var label = document.createElement('span');
				label.className = 'yts-pager-label';
				label.textContent = 'Página ' + page + ' de ' + pages;
				nav.appendChild(label);
				nav.appendChild(button('›', 'Siguiente', page < pages, function () { page++; refresh(); }));
				nav.appendChild(button('»', 'Última página', page < pages, function () { page = pages; refresh(); }));
				pager.appendChild(nav);
			}
		}

		function refresh() {
			var visible = rows().filter(matchesFilter);
			var pages = Math.max(1, Math.ceil(visible.length / per));
			if (page > pages) page = pages;
			if (page < 1) page = 1;

			rows().forEach(function (tr) { tr.hidden = true; });
			visible.slice((page - 1) * per, page * per).forEach(function (tr) { tr.hidden = false; });
			draw(visible.length, pages);
		}

		if (filterEl) filterEl.addEventListener('input', function () { page = 1; refresh(); });
		table.ytsRefresh = refresh;
		table.ytsLast = function () { page = Infinity; refresh(); };
		refresh();
	}

	/* ---------------- 3) Tabla de programas ---------------- */
	function initPrograms(table) {
		var body = table.tBodies[0];
		var next = parseInt(table.getAttribute('data-next-index') || '0', 10);
		var addBtn = document.getElementById('yts-add');
		var tpl = document.getElementById('yts-tpl');

		if (addBtn && tpl) {
			addBtn.addEventListener('click', function () {
				var filterSel = table.getAttribute('data-filter');
				var filterEl = filterSel ? document.querySelector(filterSel) : null;
				if (filterEl) filterEl.value = ''; // que la fila nueva no quede oculta por el filtro

				body.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__I__/g, String(next++)));
				var row = body.rows[body.rows.length - 1];
				initCombo(row.querySelector('.yts-combo'));
				if (table.ytsLast) table.ytsLast();
				var first = row.querySelector('input[type=text]');
				if (first) first.focus();
			});
		}

		body.addEventListener('click', function (e) {
			if (e.target.classList.contains('yts-del')) {
				var tr = e.target.closest('tr');
				tr.parentNode.removeChild(tr);
				if (table.ytsRefresh) table.ytsRefresh();
			}
		});

		body.addEventListener('change', function (e) {
			if (e.target.type === 'checkbox') {
				e.target.closest('tr').classList.toggle('yts-off', !e.target.checked);
			}
		});
	}

	/* ---------------- 4) Videos de la playlist: marcar / desmarcar todos ---------------- */
	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('[data-yts-check]') : null;
		var table = document.getElementById('yts-videos-table');
		if (!btn || !table) return;
		var on = btn.getAttribute('data-yts-check') === 'all';
		each(table.querySelectorAll('input[type=checkbox][name="pick[]"]'), function (c) { if (!c.disabled) c.checked = on; });
	});

	function start() {
		each(document.querySelectorAll('.yts-combo'), initCombo);
		each(document.querySelectorAll('table.yts-paginated'), initPager);
		var programs = document.getElementById('yts-table');
		if (programs) initPrograms(programs);
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
	else start();
})();
