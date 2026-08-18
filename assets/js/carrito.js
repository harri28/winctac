// ============================================================
// CARRITO — localStorage
// ============================================================

const Carrito = {
    KEY: 'sd_carrito',

    get() {
        try { return JSON.parse(localStorage.getItem(this.KEY)) || []; }
        catch { return []; }
    },

    save(items) {
        localStorage.setItem(this.KEY, JSON.stringify(items));
        this.updateBadge();
        document.dispatchEvent(new CustomEvent('carritoUpdated', { detail: { items } }));
    },

    agregar(producto) {
        const items = this.get();
        const idx = items.findIndex(i => i.id === producto.id);
        if (idx >= 0) {
            if (items[idx].cantidad < (producto.stock || 999)) {
                items[idx].cantidad++;
            }
        } else {
            items.push({ ...producto, cantidad: 1 });
        }
        this.save(items);
        showToast('Producto agregado al carrito', 'success');
    },

    quitar(id) {
        const items = this.get().filter(i => i.id !== id);
        this.save(items);
    },

    setCantidad(id, cantidad) {
        const items = this.get();
        const idx = items.findIndex(i => i.id === id);
        if (idx >= 0) {
            if (cantidad <= 0) { items.splice(idx, 1); }
            else { items[idx].cantidad = Math.min(cantidad, items[idx].stock || 999); }
        }
        this.save(items);
    },

    limpiar() {
        localStorage.removeItem(this.KEY);
        this.updateBadge();
        document.dispatchEvent(new CustomEvent('carritoUpdated', { detail: { items: [] } }));
    },

    total() {
        return this.get().reduce((s, i) => s + i.precio * i.cantidad, 0);
    },

    count() {
        return this.get().reduce((s, i) => s + i.cantidad, 0);
    },

    updateBadge() {
        const badge = document.getElementById('cart-badge');
        const count = this.count();
        if (badge) {
            badge.textContent = count;
            badge.classList.toggle('show', count > 0);
        }
        const countEl = document.getElementById('cart-count-text');
        if (countEl) countEl.textContent = count;
    }
};

// Toast notification
function showToast(msg, type = '') {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container';
        document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.className = 'toast' + (type ? ' ' + type : '');
    toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i> ${msg}`;
    container.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; toast.style.transition = 'opacity .3s'; setTimeout(() => toast.remove(), 300); }, 2500);
}

// Inicializar badge al cargar
document.addEventListener('DOMContentLoaded', () => Carrito.updateBadge());
