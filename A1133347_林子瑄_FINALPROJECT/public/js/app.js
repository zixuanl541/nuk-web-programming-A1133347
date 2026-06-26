// public/js/app.js — CrossProfit Pro shared JS

// Auto-dismiss flash messages
document.addEventListener('DOMContentLoaded', () => {
  const flash = document.getElementById('flash-msg');
  if (flash) {
    setTimeout(() => {
      flash.style.transition = 'opacity .5s';
      flash.style.opacity = '0';
      setTimeout(() => flash.remove(), 500);
    }, 4000);
  }

  // Close modals on backdrop click
  document.querySelectorAll('[id$="-modal"]').forEach(modal => {
    modal.addEventListener('click', e => {
      if (e.target === modal) modal.style.display = 'none';
    });
  });

  // Close modals on Escape
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      document.querySelectorAll('[id$="-modal"]').forEach(m => m.style.display = 'none');
    }
  });

  // Collapse/expand sidebar, remembered across page loads
  const toggle = document.getElementById('sidebar-toggle');
  if (toggle) {
    toggle.addEventListener('click', () => {
      const collapsed = document.documentElement.classList.toggle('sidebar-collapsed');
      localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0');
    });
  }
});
