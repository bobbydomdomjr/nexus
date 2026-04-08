/**
 * Day / night theme — slide toggle.
 * localStorage nx_dark: 'true' = night (default), 'false' = day.
 */
(function (global) {
    'use strict';
    var STORAGE = 'nx_dark';

    function storedIsDark() {
        var v = localStorage.getItem(STORAGE);
        if (v === null) return true;
        return v === 'true';
    }

    function apply() {
        var dark = storedIsDark();
        document.documentElement.classList.toggle('light', !dark);

        var btn = document.getElementById('theme-toggle-btn');
        if (!btn) return;

        var on = dark;
        btn.setAttribute('aria-checked', on ? 'true' : 'false');
        btn.setAttribute('aria-label', on ? 'Night mode on. Switch to day mode.' : 'Day mode on. Switch to night mode.');
        btn.setAttribute('title', on ? 'Switch to day mode' : 'Switch to night mode');

        var icon = btn.querySelector('[data-theme-icon]');
        if (icon) {
            icon.className = 'fas ' + (dark ? 'fa-moon' : 'fa-sun') + ' fa-fw';
        }
    }

    function toggle() {
        localStorage.setItem(STORAGE, storedIsDark() ? 'false' : 'true');
        apply();
    }

    global.nexusPublicThemeToggle = toggle;
    global.nexusPublicThemeApply = apply;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', apply);
    } else {
        apply();
    }
})(typeof window !== 'undefined' ? window : this);
