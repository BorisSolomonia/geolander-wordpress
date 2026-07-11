/* Gallery picker for the car edit screen. */
jQuery(function ($) {
	var frame;
	$('#glc-gallery-select').on('click', function (e) {
		e.preventDefault();
		if (frame) {
			frame.open();
			return;
		}
		frame = wp.media({
			title: 'Select car photos',
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
});
