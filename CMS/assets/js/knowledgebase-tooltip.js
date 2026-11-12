(function () {
    'use strict';

    function createTooltip() {
        var tooltip = document.createElement('div');
        tooltip.className = 'cms-kb-tooltip';
        tooltip.setAttribute('role', 'tooltip');

        var title = document.createElement('span');
        title.className = 'cms-kb-tooltip__title';

        var body = document.createElement('span');
        body.className = 'cms-kb-tooltip__body';

        tooltip.append(title, body);
        document.body.appendChild(tooltip);
        return tooltip;
    }

    function positionTooltip(tooltip, target) {
        var rect = target.getBoundingClientRect();
        var tooltipRect = tooltip.getBoundingClientRect();
        var top = rect.top + window.scrollY - tooltipRect.height - 14;
        var left = rect.left + window.scrollX + (rect.width / 2) - (tooltipRect.width / 2);

        if (left < 12) {
            left = 12;
        }

        if (left + tooltipRect.width > window.scrollX + window.innerWidth - 12) {
            left = window.scrollX + window.innerWidth - tooltipRect.width - 12;
        }

        if (top < window.scrollY + 12) {
            top = rect.bottom + window.scrollY + 14;
        }

        tooltip.style.top = top + 'px';
        tooltip.style.left = left + 'px';
    }

    document.addEventListener('DOMContentLoaded', function () {
        var tooltip = createTooltip();
        var titleNode = tooltip.querySelector('.cms-kb-tooltip__title');
        var bodyNode = tooltip.querySelector('.cms-kb-tooltip__body');
        var activeTarget = null;

        function hideTooltip() {
            tooltip.classList.remove('is-visible');
            activeTarget = null;
        }

        function showTooltip(target) {
            var title = target.getAttribute('data-kb-tooltip-title') || '';
            var body = target.getAttribute('data-kb-tooltip-body') || '';
            if (!body) {
                hideTooltip();
                return;
            }

            titleNode.textContent = title;
            bodyNode.textContent = body;
            tooltip.classList.add('is-visible');
            positionTooltip(tooltip, target);
            activeTarget = target;
        }

        document.addEventListener('mouseover', function (event) {
            var target = event.target instanceof Element ? event.target.closest('.cms-kb-link[data-kb-tooltip-body]') : null;
            if (!target) {
                return;
            }
            showTooltip(target);
        });

        document.addEventListener('focusin', function (event) {
            var target = event.target instanceof Element ? event.target.closest('.cms-kb-link[data-kb-tooltip-body]') : null;
            if (!target) {
                return;
            }
            showTooltip(target);
        });

        document.addEventListener('mouseout', function (event) {
            if (!(event.target instanceof Element)) {
                return;
            }
            var target = event.target.closest('.cms-kb-link[data-kb-tooltip-body]');
            if (!target) {
                return;
            }
            if (event.relatedTarget instanceof Node && target.contains(event.relatedTarget)) {
                return;
            }
            hideTooltip();
        });

        document.addEventListener('focusout', function () {
            hideTooltip();
        });

        window.addEventListener('scroll', function () {
            if (activeTarget) {
                positionTooltip(tooltip, activeTarget);
            }
        }, { passive: true });

        window.addEventListener('resize', function () {
            if (activeTarget) {
                positionTooltip(tooltip, activeTarget);
            }
        });
    });
}());
