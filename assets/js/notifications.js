/**
 * Gerenciamento de notificações - verifica expiração de downloads
 */
document.addEventListener('DOMContentLoaded', function() {
    checkExpiredDownloads();
    
    // Verifica a cada minuto
    setInterval(checkExpiredDownloads, 60000);
});

function checkExpiredDownloads() {
    const downloadLinks = document.querySelectorAll('a[data-expires-at]');
    const now = new Date();
    
    downloadLinks.forEach(link => {
        const expiresAt = new Date(link.dataset.expiresAt);
        
        if (now > expiresAt) {
            // Arquivo expirou
            link.classList.remove('btn-primary');
            link.classList.add('btn-secondary', 'disabled');
            link.setAttribute('aria-disabled', 'true');
            link.style.pointerEvents = 'none';
            link.innerHTML = 'Arquivo Expirado';
            
            // Atualiza mensagem ao lado
            const parent = link.parentElement;
            const expiredMsg = parent.querySelector('.text-muted');
            if (expiredMsg) {
                expiredMsg.innerHTML = '<span class="text-danger">(expirado - gere novamente)</span>';
            }
        } else {
            // Mostra tempo restante
            const timeLeft = Math.floor((expiresAt - now) / 1000 / 60); // minutos
            const expiredMsg = link.parentElement.querySelector('.text-muted');
            
            if (expiredMsg && timeLeft < 60) {
                expiredMsg.innerHTML = `<span class="text-warning">(expira em ${timeLeft} min)</span>`;
            }
        }
    });
}
