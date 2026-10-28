(function () {
    'use strict';

    var data = window.QTLYKL_DATA || {};
    var form = document.getElementById('qtlykl-form');
    var status = document.querySelector('[data-qtlykl-status]');
    var list = document.querySelector('[data-qtlykl-list]');
    var countdown = document.querySelector('[data-qtlykl-countdown]');
    var remaining = document.querySelector('[data-qtlykl-remaining]');
    var serverOffset = Number(data.serverNow || 0) - Math.floor(Date.now() / 1000);
    var timer;
    var checking = false;

    function post(payload) {
        var body = new URLSearchParams();
        Object.keys(payload).forEach(function (key) {
            body.append(key, payload[key]);
        });
        return fetch(data.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString()
        }).then(function (response) {
            return response.json();
        });
    }

    function setStatus(message) {
        if (status) {
            status.textContent = message || '';
        }
    }

    function addMessage(entry) {
        if (!list || !entry) {
            return;
        }
        var empty = list.querySelector('.qtlykl-empty');
        if (empty) {
            empty.remove();
        }
        var article = document.createElement('article');
        article.className = 'qtlykl-message';
        var meta = document.createElement('div');
        meta.className = 'qtlykl-message-meta';
        var author = document.createElement('span');
        author.textContent = '访客';
        var created = document.createElement('time');
        created.textContent = entry.created || '';
        meta.appendChild(author);
        meta.appendChild(created);
        var content = document.createElement('p');
        content.textContent = entry.content || '';
        article.appendChild(meta);
        article.appendChild(content);
        list.prepend(article);
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var button = form.querySelector('button[type="submit"]');
            var content = form.querySelector('[name="content"]');
            if (!content || !content.value.trim() || !button) {
                setStatus('请填写留言。');
                return;
            }
            button.disabled = true;
            setStatus('正在提交…');
            post({
                action: 'qtlykl_submit',
                nonce: data.submitNonce || '',
                request_id: data.requestId || '',
                content: content.value
            }).then(function (result) {
                if (!result || !result.success) {
                    setStatus(result && result.data && result.data.message ? result.data.message : '提交失败，请稍后再试。');
                    return;
                }
                if (result.data && result.data.redirect) {
                    window.location.replace(result.data.redirect);
                    return;
                }
                addMessage(result.data && result.data.entry);
                content.value = '';
                setStatus(result.data && result.data.message ? result.data.message : '留言成功。');
                data.requestId = window.crypto && typeof window.crypto.randomUUID === 'function'
                    ? window.crypto.randomUUID()
                    : String(Date.now()) + Math.random();
            }).catch(function () {
                setStatus('提交失败，请稍后再试。');
            }).finally(function () {
                button.disabled = false;
            });
        });
    }

    function format(seconds) {
        var minutes = Math.floor(seconds / 60);
        var rest = seconds % 60;
        return String(minutes).padStart(2, '0') + ':' + String(rest).padStart(2, '0');
    }

    function redirectHome() {
        window.location.replace(data.homeUrl || '/');
    }

    function refreshStatus() {
        if (checking || !data.ajaxUrl || data.mode !== 'active') {
            return;
        }
        checking = true;
        post({action: 'qtlykl_status', nonce: data.statusNonce || ''}).then(function (result) {
            if (!result || !result.success || !result.data) {
                if (remaining && Number(data.expiresAt) <= Math.floor(Date.now() / 1000) + serverOffset) {
                    redirectHome();
                }
                return;
            }
            if (result.data.logged_in || result.data.state !== 'active') {
                redirectHome();
                return;
            }
            data.serverNow = Number(result.data.server_now || data.serverNow);
            data.expiresAt = Number(result.data.expires_at || data.expiresAt);
            serverOffset = Number(data.serverNow) - Math.floor(Date.now() / 1000);
        }).catch(function () {
            if (remaining && Number(data.expiresAt) <= Math.floor(Date.now() / 1000) + serverOffset) {
                redirectHome();
            }
        }).finally(function () {
            checking = false;
        });
    }

    function drawCountdown() {
        if (!countdown || !remaining || data.mode !== 'active') {
            return;
        }
        var now = Math.floor(Date.now() / 1000) + serverOffset;
        var seconds = Math.max(0, Number(data.expiresAt || 0) - now);
        remaining.textContent = format(seconds);
        if (seconds <= 0) {
            refreshStatus();
        }
    }

    if (countdown) {
        drawCountdown();
        timer = window.setInterval(drawCountdown, 1000);
        window.setInterval(refreshStatus, 30000);
        window.addEventListener('pageshow', refreshStatus);
        window.addEventListener('online', refreshStatus);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                refreshStatus();
            }
        });
    }
}());
