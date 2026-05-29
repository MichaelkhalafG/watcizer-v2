import { useEffect } from "react";

const useFacebookPixel = (pixelId) => {
    useEffect(() => {
        const loadPixel = () => {
            if (window.fbq) return;

            window.fbq = function () {
                window.fbq.callMethod
                    ? window.fbq.callMethod.apply(window.fbq, arguments)
                    : window.fbq.queue.push(arguments);
            };

            window.fbq.queue = [];
            window.fbq.version = "2.0";
            window.fbq.loaded = true;

            const script = document.createElement("script");
            script.async = true;
            script.defer = true;
            script.src = "https://connect.facebook.net/en_US/fbevents.js";
            script.onload = () => {
                window.fbq("init", pixelId);
                window.fbq("track", "PageView");
            };

            document.head.appendChild(script);
        };

        if (document.readyState === "complete") {
            loadPixel();
        } else {
            window.addEventListener("load", loadPixel);
        }

        return () => {
            window.removeEventListener("load", loadPixel);
        };
    }, [pixelId]);
};

export default useFacebookPixel;
