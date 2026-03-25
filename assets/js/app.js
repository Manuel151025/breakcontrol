// ============================================================
//  JAVASCRIPT GENERAL
//  Archivo: assets/js/app.js
// ============================================================

// Confirmar acciones destructivas
document.querySelectorAll('[data-confirmar]').forEach(btn => {
  btn.addEventListener('click', e => {
    const msg = btn.dataset.confirmar || '¿Está seguro?';
    if (!confirm(msg)) e.preventDefault();
  });
});

// Marcar enlace activo en el navbar según la URL actual
document.querySelectorAll('.navbar .nav-link').forEach(link => {
  if (window.location.href.includes(link.getAttribute('href'))) {
    link.classList.add('active');
  }
});

// Formatear campos de peso/cantidad en tiempo real
document.querySelectorAll('.input-cantidad').forEach(input => {
  input.addEventListener('input', () => {
    const val = parseFloat(input.value);
    if (!isNaN(val) && val < 0) input.value = 0;
  });
});
