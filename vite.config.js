import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig(({ command, mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const appPath = new URL(env.APP_URL || 'http://localhost').pathname.replace(/\/$/, '');
    const assetRoot = (env.ASSET_URL ?? appPath).replace(/\/$/, '');

    return {
        base: command === 'build' ? `${assetRoot}/build/` : '',
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                refresh: true,
            }),
        ],
        server: {
            host: '127.0.0.1',
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
