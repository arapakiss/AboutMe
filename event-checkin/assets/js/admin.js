/**
 * Event Check-in - Admin JavaScript
 */
(function ($) {
    'use strict';

    var fieldIndex = $('.ec-custom-field').length;

    // Add custom field row.
    $('#ec-add-field').on('click', function () {
        var html = '<div class="ec-custom-field" data-index="' + fieldIndex + '">' +
            '<input type="text" name="ec_cf_label[]" value="" placeholder="Field Label">' +
            '<select name="ec_cf_type[]">' +
            '<option value="text">Text</option>' +
            '<option value="textarea">Textarea</option>' +
            '<option value="select">Select</option>' +
            '<option value="checkbox">Checkbox</option>' +
            '</select>' +
            '<input type="text" name="ec_cf_options[]" value="" placeholder="Options (comma-separated, for select)">' +
            '<label><input type="checkbox" name="ec_cf_required[' + fieldIndex + ']" value="1"> Required</label>' +
            '<button type="button" class="button ec-remove-field">&times;</button>' +
            '</div>';
        $('#ec-custom-fields').append(html);
        fieldIndex++;
    });

    // Remove custom field row.
    $(document).on('click', '.ec-remove-field', function () {
        $(this).closest('.ec-custom-field').remove();
    });

})(jQuery);
