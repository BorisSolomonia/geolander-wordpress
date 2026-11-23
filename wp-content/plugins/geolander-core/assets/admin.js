/*
 * The car edit screen: the gallery picker, and what the server answered when
 * the boxes under the editor were saved.
 */
jQuery(function ($) {
	var cfg = window.glcCarEditor || {};
	var frame;
	$('#glc-gallery-select').on('click', function (e) {
		e.preventDefault();
		if (frame) {
			frame.open();
			return;
		}
		frame = wp.media({
			title: cfg.pickTitle || '',
			multiple: 'add',
			library: { type: 'image' },
		});
		frame.on('open', function () {
			var ids = $('#glc_gallery').val().split(',').filter(Boolean);
			var selection = frame.state().get('selection');
			ids.forEach(function (id) {
				var att = wp.media.attachment(id);
				att.fetch();
				selection.add(att);
			});
		});
		frame.on('select', function () {
			var models = frame.state().get('selection').models;
			$('#glc_gallery').val(models.map(function (m) { return m.id; }).join(','));
			$('#glc-gallery-preview').html(models.map(function (m) {
				var sizes = m.get('sizes') || {};
				var url = (sizes.thumbnail || sizes.full || {}).url || m.get('url');
				return '<img src="' + url + '" style="width:70px;height:70px;object-fit:cover;margin:2px;" />';
			}).join(''));
		});
		frame.open();
	});

	/*
	 * The block editor saves these boxes behind the page and shows nothing of
	 * the answer. The details and the gallery go through a check that refuses a
	 * save made from a page opened before someone else's change: when that
	 * happens the owner has to be told, and when the owner's own save went
	 * through, the page has to learn the car's new revision or its next save
	 * would be refused.
	 */
	var data = window.wp && wp.data;
	/*
	 * The main photo is chosen on the car's own screen, through the same check
	 * as the gallery. The editor's "Featured image" panel would send it past
	 * that check (the server refuses it), so the panel is not offered here.
	 */
	var panels = data && data.dispatch('core/editor');
	if (panels && panels.removeEditorPanel) {
		panels.removeEditorPanel('featured-image');
	}
	var editor = data && data.select('core/edit-post');
	if (!editor || !editor.isSavingMetaBoxes || !cfg.status) {
		return;
	}
	var NOTICE = 'glc-store-notice';
	var saving = false;
	data.subscribe(function () {
		var now = editor.isSavingMetaBoxes();
		var done = saving && !now;
		saving = now;
		if (!done) {
			return;
		}
		window.fetch(cfg.status, { credentials: 'same-origin' })
			.then(function (response) { return response.ok ? response.json() : null; })
			.then(function (answer) {
				if (!answer) {
					return;
				}
				if (answer.refused) {
					data.dispatch('core/notices').createErrorNotice(answer.lines.join(' '), { id: NOTICE, isDismissible: true });
				} else {
					data.dispatch('core/notices').removeNotice(NOTICE);
					// Only a revision this very save produced: the server sends none otherwise.
					if (answer.rev !== null) {
						$('#glc_rev').val(answer.rev);
					}
				}
			})
			.catch(function () {});
	});
});
