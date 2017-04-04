(function ($) {
    $(function() {
        $('button.navbar-toggle').each(function () {
            var button = $(this);
            var slideout = $(button.attr('data-target'));
            var overlay = slideout.children('.slideout-overlay');

            button.click(function (e) {
                e.preventDefault();

                if (button.hasClass('open')) {
                    button.removeClass('open');
                    slideout.removeClass('open');
                } else {
                    button.addClass('open');
                    slideout.addClass('open');
                }
            });

            overlay.click(function (e) {
                e.preventDefault();
                button.removeClass('open');
                slideout.removeClass('open');
            })
        });
    });
})(jQuery);