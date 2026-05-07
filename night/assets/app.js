// app.js
(() => {
  const qs = (s, el=document) => el.querySelector(s);
  const qsa = (s, el=document) => Array.from(el.querySelectorAll(s));

  // Smooth scroll
  qsa('a[href^="#"]').forEach(a => {
    a.addEventListener('click', (e) => {
      const id = a.getAttribute('href');
      if (!id || id === '#') return;
      const target = qs(id);
      if (!target) return;
      e.preventDefault();
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  // Modal open/close
  const modal = qs('#aiModal');
  const openBtns = qsa('[data-open-ai-demo]');
  const closeBtns = qsa('[data-close-modal]');
  const openModal = () => {
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    setTimeout(() => qs('#aiQuestion')?.focus(), 50);
  };
  const closeModal = () => {
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
  };
  openBtns.forEach(b => b.addEventListener('click', openModal));
  closeBtns.forEach(b => b.addEventListener('click', closeModal));
  window.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });

  // AI demo runner (front-only mock)
  const runBtn = qs('#aiRun');
  const out = qs('#aiOut');
  const q = qs('#aiQuestion');
  if (runBtn && out && q) {
    runBtn.addEventListener('click', () => {
      const text = (q.value || '').trim();
      out.innerHTML = `<div class="aiOutHint">解析中…</div>`;
      runBtn.disabled = true;

      // 擬似生成（本番はAPI連携）
      setTimeout(() => {
        const answer = [
          `【質問】${escapeHtml(text || '（未入力）')}`,
          '',
          '■ 結論',
          '売上入力漏れ・シフト過多・打刻不足が同時に起きている可能性が高いです。',
          '',
          '■ 主要な問題（優先度順）',
          '1) 売上0の日が多い → 入力フロー確認 / 0=定休日の定義を統一',
          '2) 人件費率が高い → ピーク時間帯以外のシフト削減 / 休憩計上の見直し',
          '3) 打刻データ不足 → iPad/LINEの運用ルール化 / 未打刻を自動アラート',
          '',
          '■ すぐやる改善（今週）',
          '・売上未入力疑い日を一覧で確認し、入力漏れを埋める',
          '・人件費率が高い日だけシフトを再設計（人員と時間）',
          '・退勤漏れを減らす運用（閉店前の一斉確認など）'
        ];

        out.innerHTML = `<pre style="margin:0; white-space:pre-wrap; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; font-size:12.5px; color:#0f172a;">${escapeHtml(answer.join('\n'))}</pre>`;
        runBtn.disabled = false;
      }, 900);
    });
  }

  // Simple form validation
  qsa('form[data-validate]').forEach(form => {
    form.addEventListener('submit', (e) => {
      const type = form.getAttribute('data-validate');
      const email = form.querySelector('input[type="email"]');
      const required = qsa('[required]', form);

      let ok = true;
      required.forEach(el => {
        if (!String(el.value || '').trim()) {
          ok = false;
          el.style.borderColor = 'rgba(239,68,68,.55)';
        } else {
          el.style.borderColor = 'rgba(226,232,240,.95)';
        }
      });

      if (email && email.value) {
        const v = email.value.trim();
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!re.test(v)) {
          ok = false;
          email.style.borderColor = 'rgba(239,68,68,.55)';
        }
      }

      if (!ok) {
        e.preventDefault();
        alert(type === 'apply'
          ? '申込フォームの必須項目を確認してください。'
          : 'お問い合わせフォームの必須項目を確認してください。');
      }
    });
  });

  function escapeHtml(s) {
    return String(s)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }
})();
