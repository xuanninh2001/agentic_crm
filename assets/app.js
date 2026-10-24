(function () {
    'use strict';

    var clock = document.getElementById('local-clock');
    var timeFormatter = new Intl.DateTimeFormat('vi-VN', {
        timeZone: 'Asia/Ho_Chi_Minh',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false
    });

    function updateClock() {
        if (clock) {
            clock.textContent = timeFormatter.format(new Date());
        }
    }

    function formatRelative(date) {
        var seconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));
        if (seconds < 10) return 'vừa xong';
        if (seconds < 60) return seconds + ' giây trước';
        if (seconds < 3600) return Math.floor(seconds / 60) + ' phút trước';
        if (seconds < 86400) return Math.floor(seconds / 3600) + ' giờ trước';
        return Math.floor(seconds / 86400) + ' ngày trước';
    }

    function updateRelativeTimes() {
        document.querySelectorAll('[data-relative-time]').forEach(function (element) {
            var date = new Date(element.getAttribute('datetime'));
            if (!isNaN(date.getTime())) {
                element.textContent = formatRelative(date);
            }
        });
    }

    document.querySelectorAll('[data-auto-submit]').forEach(function (select) {
        select.addEventListener('change', function () {
            select.form.submit();
        });
    });

    document.querySelectorAll('.expand-action').forEach(function (button) {
        button.addEventListener('click', function () {
            var content = document.getElementById(button.getAttribute('aria-controls'));
            var expanded = button.getAttribute('aria-expanded') === 'true';
            if (!content) return;

            button.setAttribute('aria-expanded', String(!expanded));
            button.querySelector('.expand-label').textContent = expanded ? 'Mở rộng' : 'Thu gọn';
            content.hidden = expanded;
        });
    });

    function setCount(selector, delta) {
        document.querySelectorAll(selector).forEach(function (element) {
            var current = parseInt(element.textContent, 10) || 0;
            element.textContent = String(Math.max(0, current + delta));
        });
    }

    function showToast(message) {
        var oldToast = document.querySelector('[data-toast]');
        if (oldToast) oldToast.remove();

        var toast = document.createElement('div');
        toast.className = 'toast';
        toast.setAttribute('role', 'status');
        toast.setAttribute('data-toast', '');
        toast.textContent = message;
        document.body.appendChild(toast);
        window.setTimeout(function () { toast.classList.add('is-hiding'); }, 2200);
        window.setTimeout(function () { toast.remove(); }, 2600);
    }

    function resetSwipe(item) {
        var surface = item.querySelector('.swipe-surface');
        if (!surface) return;
        surface.style.transition = 'transform 240ms cubic-bezier(.22, .8, .32, 1)';
        surface.style.transform = 'translateX(0)';
        item.classList.remove('swiping-left', 'swiping-right', 'threshold-reached');
        window.setTimeout(function () { surface.style.transition = ''; }, 250);
    }

    function performItemAction(item, action) {
        if (item.classList.contains('is-working')) return;

        var form = item.querySelector(action === 'archive' ? '.archive-form' : '.delete-form');
        var surface = item.querySelector('.swipe-surface');
        if (!form || !surface) {
            resetSwipe(item);
            return;
        }

        item.classList.add('is-working', action === 'archive' ? 'swiping-right' : 'swiping-left');
        surface.style.transition = 'transform 220ms cubic-bezier(.4, 0, .2, 1)';
        surface.style.transform = 'translateX(' + (action === 'archive' ? '110%' : '-110%') + ')';

        fetch(form.getAttribute('action') || window.location.href, {
            method: 'POST',
            body: new FormData(form),
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        }).then(function (response) {
            if (!response.ok) throw new Error('request-failed');
            return response.text();
        }).then(function () {
            var wasArchivable = item.getAttribute('data-archivable') === 'true';
            if (action === 'archive') {
                setCount('[data-inbox-count], [data-tab-inbox-count]', -1);
                setCount('[data-archive-count], [data-tab-archive-count]', 1);
            } else if (wasArchivable) {
                setCount('[data-inbox-count], [data-tab-inbox-count]', -1);
            } else {
                setCount('[data-archive-count], [data-tab-archive-count]', -1);
            }

            var height = item.offsetHeight;
            item.style.height = height + 'px';
            item.style.opacity = '1';
            item.style.transition = 'height 240ms ease, opacity 180ms ease, padding 240ms ease';
            window.requestAnimationFrame(function () {
                item.style.height = '0';
                item.style.opacity = '0';
            });

            showToast(action === 'archive' ? 'Đã lưu trữ nội dung.' : 'Đã xoá nội dung.');
            window.setTimeout(function () {
                item.remove();
                if (!document.querySelector('.feed-item')) window.location.reload();
            }, 260);
        }).catch(function () {
            item.classList.remove('is-working');
            resetSwipe(item);
            showToast('Không thể thực hiện thao tác. Vui lòng thử lại.');
        });
    }

    document.querySelectorAll('[data-swipe-item]').forEach(function (item) {
        var surface = item.querySelector('.swipe-surface');
        if (!surface) return;

        var startX = 0;
        var startY = 0;
        var deltaX = 0;
        var tracking = false;
        var horizontal = false;

        surface.addEventListener('pointerdown', function (event) {
            if (item.classList.contains('is-working')) return;
            if (!event.isPrimary || (event.pointerType === 'mouse' && event.button !== 0)) return;
            if (event.target.closest('button, a, input, select, textarea, label')) return;
            startX = event.clientX;
            startY = event.clientY;
            deltaX = 0;
            tracking = true;
            horizontal = false;
            surface.style.transition = 'none';
        });

        surface.addEventListener('pointermove', function (event) {
            if (!tracking) return;
            var dx = event.clientX - startX;
            var dy = event.clientY - startY;

            if (!horizontal) {
                if (Math.abs(dx) < 9 && Math.abs(dy) < 9) return;
                if (Math.abs(dy) > Math.abs(dx) * 1.15) {
                    tracking = false;
                    resetSwipe(item);
                    return;
                }
                if (Math.abs(dx) <= Math.abs(dy) * 1.15) return;
                horizontal = true;
                item.classList.add('is-swiping');
                if (surface.setPointerCapture) surface.setPointerCapture(event.pointerId);
            }

            event.preventDefault();
            deltaX = dx;
            if (deltaX > 0 && item.getAttribute('data-archivable') !== 'true') {
                deltaX *= 0.16;
            }

            var limit = item.offsetWidth * 0.86;
            deltaX = Math.max(-limit, Math.min(limit, deltaX));
            surface.style.transform = 'translateX(' + deltaX + 'px)';
            item.classList.toggle('swiping-right', deltaX > 0);
            item.classList.toggle('swiping-left', deltaX < 0);

            var threshold = Math.min(124, Math.max(88, item.offsetWidth * 0.3));
            item.classList.toggle('threshold-reached', Math.abs(deltaX) >= threshold);
        }, { passive: false });

        function finishSwipe(event) {
            if (!tracking && !horizontal) return;
            tracking = false;
            item.classList.remove('is-swiping');
            if (event && surface.hasPointerCapture && surface.hasPointerCapture(event.pointerId)) {
                surface.releasePointerCapture(event.pointerId);
            }

            var threshold = Math.min(124, Math.max(88, item.offsetWidth * 0.3));
            if (deltaX <= -threshold) {
                performItemAction(item, 'delete');
            } else if (deltaX >= threshold && item.getAttribute('data-archivable') === 'true') {
                performItemAction(item, 'archive');
            } else {
                resetSwipe(item);
            }
            horizontal = false;
            deltaX = 0;
        }

        surface.addEventListener('pointerup', finishSwipe);
        surface.addEventListener('pointercancel', function () {
            tracking = false;
            horizontal = false;
            deltaX = 0;
            item.classList.remove('is-swiping');
            resetSwipe(item);
        });
    });

    document.querySelectorAll('[data-archive]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            performItemAction(button.closest('[data-swipe-item]'), 'archive');
        });
    });

    var dialog = document.getElementById('delete-dialog');
    var pendingForm = null;

    document.querySelectorAll('[data-delete]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            if (!dialog || typeof dialog.showModal !== 'function') {
                if (!window.confirm('Xoá nội dung này? Thao tác không thể hoàn tác.')) {
                    event.preventDefault();
                }
                return;
            }

            event.preventDefault();
            pendingForm = button.closest('form');
            var summary = button.getAttribute('data-summary') || 'Nội dung đã chọn';
            document.getElementById('dialog-summary').textContent = '“' + summary + '” sẽ bị xoá vĩnh viễn.';
            dialog.showModal();
        });
    });

    if (dialog) {
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                dialog.close('cancel');
            }
        });

        dialog.addEventListener('close', function () {
            if (dialog.returnValue === 'confirm' && pendingForm) {
                pendingForm.submit();
            }
            pendingForm = null;
        });
    }

    document.querySelectorAll('[data-copy-target]').forEach(function (button) {
        button.addEventListener('click', function () {
            var target = document.getElementById(button.getAttribute('data-copy-target'));
            if (!target || !navigator.clipboard) return;
            navigator.clipboard.writeText(target.textContent).then(function () {
                var original = button.textContent;
                button.textContent = 'Đã sao chép';
                window.setTimeout(function () { button.textContent = original; }, 1600);
            });
        });
    });

    var toast = document.querySelector('[data-toast]');
    if (toast) {
        window.setTimeout(function () { toast.classList.add('is-hiding'); }, 2600);
    }

    updateClock();
    updateRelativeTimes();
    window.setInterval(updateClock, 1000);
    window.setInterval(updateRelativeTimes, 30000);
}());
