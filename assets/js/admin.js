/* ViralPlus Admin JS */
(function ($) {
    'use strict';

    $(document).on('click', '.vp-admin-btn', function () {
        var $btn   = $(this);
        var action = $btn.data('action');
        var id     = $btn.data('id');

        if (!confirm('آیا مطمئن هستید؟')) return;

        $btn.prop('disabled', true).text('...');

        $.post(ajaxurl, {
            action:    'vp_admin_action',
            nonce:     VP_Admin.nonce,
            vp_action: action,
            item_id:   id
        }, function (res) {
            if (res.success) {
                alert(res.data);
                location.reload();
            } else {
                alert('خطا: ' + res.data);
                $btn.prop('disabled', false).text('تلاش مجدد');
            }
        });
    });

})(jQuery);