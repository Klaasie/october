+function ($) {
    "use strict";

    let Base = $.oc.foundation.base,
        BaseProto = Base.prototype;

    let TWDropdown = function (element, options) {
        let $el = this.$el = $(element);
        this.options = options || {};

        Base.call(this);
        $($el).on('click', this.proxy(this.toggle));
        $('#overlay').on('click', this.proxy(this.toggle));
    };

    TWDropdown.prototype = Object.create(BaseProto);
    TWDropdown.prototype.constructor = TWDropdown;

    TWDropdown.prototype.toggle = function () {
        $(this.$el).toggleClass('dropdown-open');
        $('#overlay').toggle();
        $(this.options.dropdownTarget).toggle();
    };

    TWDropdown.DEFAULTS = {
    };

    // SIMPLE LIST PLUGIN DEFINITION
    // ============================

    let old = $.fn.twdropdown;

    $.fn.twdropdown = function (option) {
        return this.each(function () {
            let $this = $(this);
            let data  = $this.data('dropdown');
            let options = $.extend({}, TWDropdown.DEFAULTS, $this.data(), typeof option == 'object' && option);
            if (!data) $this.data('dropdown', (data = new TWDropdown(this, options)))
        })
    };

    $.fn.twdropdown.Constructor = TWDropdown;

    // SIMPLE LIST NO CONFLICT
    // =================

    $.fn.twdropdown.noConflict = function () {
        $.fn.twdropdown = old;
        return this;
    };

    // SIMPLE LIST DATA-API
    // ===============

    $(document).render(function(){
        $('[data-control="dropdown"]').twdropdown();
    });
}(window.jQuery);
