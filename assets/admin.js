/*
 * YouTube a Streamit — panel de administración.
 * Sin dependencias. Hace cinco cosas:
 *   1) Selector con buscador (.yts-combo): programas de Streamit y programas sincronizados, alimentado por window.YTS.
 *   2) Paginación y filtro de tablas (table.yts-paginated). Todas las filas siguen en el formulario aunque estén ocultas.
 *   3) Tabla de programas: agregar, quitar y activar/desactivar filas.
 *   4) Videos de la playlist: botones Marcar todos / Desmarcar todos.
 *   5) Tablas como tarjetas (table.yts-stack → .yts-card-mode) según el ancho del contenedor.
 */
(function () {
	'use strict';

	var each = function (list, fn) { Array.prototype.forEach.call(list, fn); };

	// Textos traducidos que entrega PHP en window.YTS.i18n (con el inglés como respaldo)
	var I18N = (window.YTS && window.YTS.i18n) || {};
	function t(key, fallback) { return I18N[key] || fallback; }
	// Reemplaza %1$s, %2$s… (o %s en orden) por los argumentos
	function fmt(str) {
		var args = Array.prototype.slice.call(arguments, 1), next = 0;
		return String(str).replace(/%(?:(\d+)\$)?s/g, function (m, n) { return args[n ? n - 1 : next++]; });
	}

	// Minúsculas y sin acentos, para que "camara" encuentre "Cámara"
	function norm(s) {
		s = String(s == null ? '' : s).toLowerCase();
		return s.normalize ? s.normalize('NFD').replace(/[\u0300-\u036f]/g, '') : s;
	}

	/* ---------------- 1) Selector con buscador ---------------- */
	/*
	 * <div class="yts-combo" data-source="shows|programs" data-submit="1"> con un input de texto, un input oculto (el valor
	 * que se envía) y la lista. Las opciones salen de window.YTS[data-source] = [{ id, title, hint? }]:
	 *   shows    → programas de Streamit (Programas sincronizados)
	 *   programs → programas ya sincronizados (Videos de la playlist)
	 * Con data-submit="1", al elegir una opción se envía el formulario que lo contiene.
	 */
	var MAX_ITEMS = 50;

	function initCombo(box) {
		if (!box || box.getAttribute('data-ready')) return;
		box.setAttribute('data-ready', '1');

		var source = box.getAttribute('data-source') || 'shows';
		var autoSubmit = !!box.getAttribute('data-submit');
		var input = box.querySelector('.yts-combo-input');
		var hidden = box.querySelector('input[type=hidden]');
		var list = box.querySelector('.yts-combo-list');
		var matches = [];
		var active = -1;
		var typing = false;

		function items() { return (window.YTS && window.YTS[source]) || []; }

		function titleOf(id) {
			var all = items();
			for (var i = 0; i < all.length; i++) {
				if (String(all[i].id) === String(id)) return all[i].title;
			}
			return '';
		}

		function labelOf(it) {
			return it.title + '  (' + (it.hint != null && it.hint !== '' ? it.hint : '#' + it.id) + ')';
		}

		function render() {
			var q = typing ? norm(input.value) : '';
			var all = items().filter(function (it) {
				if (!q) return true;
				return norm(it.title).indexOf(q) !== -1 ||
					(it.hint != null && norm(it.hint).indexOf(q) !== -1) ||
					(source === 'shows' && String(it.id) === q);
			});
			matches = all.slice(0, MAX_ITEMS);
			active = -1;
			list.innerHTML = '';

			if (!matches.length) {
				var empty = document.createElement('div');
				empty.className = 'yts-combo-empty';
				empty.textContent = items().length ? t('noResults', 'No results') : t('noPrograms', 'There are no programs yet');
				list.appendChild(empty);
			}
			matches.forEach(function (it) {
				var d = document.createElement('div');
				d.className = 'yts-combo-item' + (String(it.id) === hidden.value ? ' is-selected' : '');
				d.textContent = labelOf(it);
				d.addEventListener('mousedown', function (e) { e.preventDefault(); }); // ratón: no quitar el foco del campo
				d.addEventListener('click', function () { pick(it); });                // ratón y toque
				list.appendChild(d);
			});
			if (all.length > MAX_ITEMS) {
				var note = document.createElement('div');
				note.className = 'yts-combo-note';
				note.textContent = fmt(t('showing', 'Showing %1$s of %2$s. Keep typing to narrow it down.'), MAX_ITEMS, all.length);
				list.appendChild(note);
			}
			list.hidden = false;
		}

		function pick(it) {
			var changed = String(hidden.value) !== String(it.id);
			hidden.value = it.id;
			input.value = it.title;
			typing = false;
			list.hidden = true;
			if (autoSubmit && changed) {
				var form = box.closest('form');
				if (form) form.submit();
			}
		}

		function move(dir) {
			var els = list.querySelectorAll('.yts-combo-item');
			if (!els.length) return;
			active = (active + dir + els.length) % els.length;
			each(els, function (el, i) { el.classList.toggle('is-active', i === active); });
			if (els[active].scrollIntoView) els[active].scrollIntoView({ block: 'nearest' });
		}

		input.addEventListener('focus', function () { typing = false; input.select(); render(); });
		input.addEventListener('input', function () { typing = true; render(); });
		input.addEventListener('blur', function () {
			// Pequeña espera: en el celular el toque sobre una opción llega después del blur
			setTimeout(function () {
				if (document.activeElement === input) return;
				list.hidden = true;
				input.value = titleOf(hidden.value); // si no eligió nada nuevo, vuelve al elegido
			}, 200);
		});
		input.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowDown') { e.preventDefault(); if (list.hidden) render(); move(1); }
			else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
			else if (e.key === 'Enter') {
				if (!list.hidden) {
					e.preventDefault(); // no enviar el formulario con Enter mientras la lista está abierta
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
		var kindSel = table.getAttribute('data-filter-select'); // opcional: <select> que filtra por tr[data-code]
		var kindEl = kindSel ? document.querySelector(kindSel) : null;
		var page = 1;

		var pager = document.createElement('div');
		pager.className = 'yts-pager';
		table.parentNode.insertBefore(pager, table.nextSibling);

		function rows() { return Array.prototype.slice.call(body.rows); }

		function matchesFilter(tr) {
			if (kindEl && kindEl.value && tr.getAttribute('data-code') !== kindEl.value) return false;
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
			var filtering = !!((filterEl && filterEl.value) || (kindEl && kindEl.value));
			if (total <= per && !filtering) return; // nada que paginar

			var info = document.createElement('span');
			info.className = 'displaying-num';
			info.textContent = total === 1 ? t('oneItem', '1 item') : fmt(t('nItems', '%s items'), total);
			pager.appendChild(info);

			if (pages > 1) {
				var nav = document.createElement('span');
				nav.className = 'yts-pager-links';
				nav.appendChild(button('«', t('firstPage', 'First page'), page > 1, function () { page = 1; refresh(); }));
				nav.appendChild(button('‹', t('prevPage', 'Previous'), page > 1, function () { page--; refresh(); }));
				var label = document.createElement('span');
				label.className = 'yts-pager-label';
				label.textContent = fmt(t('pageOf', 'Page %1$s of %2$s'), page, pages);
				nav.appendChild(label);
				nav.appendChild(button('›', t('nextPage', 'Next'), page < pages, function () { page++; refresh(); }));
				nav.appendChild(button('»', t('lastPage', 'Last page'), page < pages, function () { page = pages; refresh(); }));
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
		if (kindEl) kindEl.addEventListener('change', function () { page = 1; refresh(); });
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

	/* ---------------- 5) Tablas como tarjetas según el ancho disponible ---------------- */
	// Mide el ancho del contenedor (no de la pantalla): así cuenta el menú lateral de WordPress y sirve también en tablets.
	function initCards() {
		var tables = document.querySelectorAll('table.yts-stack');
		function update() {
			each(tables, function (tb) {
				var below = parseInt(tb.getAttribute('data-cards-below') || '720', 10);
				var holder = tb.parentNode;
				var w = holder ? holder.clientWidth : window.innerWidth;
				tb.classList.toggle('yts-card-mode', w > 0 && w < below);
			});
		}
		update();
		window.addEventListener('resize', update);
		window.addEventListener('orientationchange', update);
		if (window.ResizeObserver) {
			var wrap = document.querySelector('.yts-wrap');
			if (wrap) new ResizeObserver(update).observe(wrap);
		}
	}

	function start() {
		each(document.querySelectorAll('.yts-combo'), initCombo);
		each(document.querySelectorAll('table.yts-paginated'), initPager);
		var programs = document.getElementById('yts-table');
		if (programs) initPrograms(programs);
		initCards();
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
	else start();
})();
