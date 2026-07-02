import './bootstrap';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
// Reload hooks for CashFlow

console.log("Starting Web App... Invoices");

createInertiaApp({
    title: (title) => `${title} - KiotViet Clone`,
    resolve: (name) => {
        return resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue'))
            .catch(err => {
                document.body.innerHTML += `<div style="padding: 20px; background: #fff0f0; color: #d00; border: 1px solid #d00; position: fixed; top: 0; left: 0; right: 0; z-index: 9999;"><h3>Resolve Error:</h3><pre>${err.message}\n${err.stack}</pre></div>`;
                throw err;
            });
    },
    setup({ el, App, props, plugin }) {
        const vueApp = createApp({ render: () => h(App, props) });
        vueApp.use(plugin);
        vueApp.config.errorHandler = (err, instance, info) => {
            console.error("Vue Global Error:", err, info);
            document.body.innerHTML += `<div style="padding: 20px; background: #fff0f0; color: #d00; border: 1px solid #d00; position: fixed; top: 0; left: 0; right: 0; z-index: 9999;"><h3>Vue Error:</h3><pre>${err.message}\n${err.stack}</pre></div>`;
        };
        vueApp.mount(el);
    },
});
