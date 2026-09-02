import { useEffect, useRef } from 'react';
import { usePage } from '@inertiajs/react';
import notify from './notify';

/** Toast the server's flash messages once per page visit. */
export default function useFlash() {
    const { flash } = usePage().props;
    const seen = useRef(null);

    useEffect(() => {
        if (!flash) return;
        const key = JSON.stringify([flash.success, flash.error, flash.info, flash.warning]);
        if (key === seen.current) return;
        seen.current = key;
        if (flash.success) notify.success(flash.success);
        if (flash.error) notify.error(flash.error);
        if (flash.warning) notify.warning(flash.warning);
        if (flash.info) notify.info(flash.info);
    }, [flash]);
}
