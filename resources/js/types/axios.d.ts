import 'axios';

declare module 'axios' {
    interface AxiosRequestConfig {
        /** Background request (polling, debounced refresh, typing ping): never shows the global loading bar. */
        silent?: boolean;
    }
}
