import { X, Camera } from "lucide-react";
import { useEffect, useRef, useState } from "react";

/*
 * Capture photo via la caméra — getUserMedia + canvas, sans dépendance.
 *
 * Produit un JPEG prêt pour l'upload (même pipeline que le fichier choisi à la
 * main : le contrôleur valide déjà jpg/jpeg/png/webp/avif). Le flux est arrêté
 * au démontage ET à chaque fermeture : une caméra allumée en arrière-plan est
 * un voyant qui reste allumé pour rien.
 *
 * getUserMedia exige un contexte sécurisé (HTTPS, ou localhost en dev).
 */
export default function CameraCaptureModal({ open, onClose, onCapture }) {
    const videoRef = useRef(null);
    const streamRef = useRef(null);
    const [error, setError] = useState(null);
    const [starting, setStarting] = useState(false);

    const stopStream = () => {
        if (streamRef.current) {
            streamRef.current.getTracks().forEach((track) => track.stop());
            streamRef.current = null;
        }
    };

    useEffect(() => {
        if (!open) {
            stopStream();
            return;
        }

        let cancelled = false;
        setError(null);
        setStarting(true);

        // Une caméra bloquée au niveau du site (en-tête Permissions-Policy) ou un
        // refus mémorisé échoue tous deux en NotAllowedError SANS que le navigateur
        // n'affiche la moindre invite. On interroge l'état de la permission d'abord,
        // pour afficher le bon conseil selon le cas.
        navigator.permissions
            ?.query({ name: "camera" })
            .then(({ state }) => {
                if (state === "denied" && !cancelled) {
                    setError(
                        "La caméra est bloquée pour ce site. Cliquez sur l'icône ⋮ ⋯ / cadenas à gauche de l'adresse → autorisez la caméra → rechargez la page.",
                    );
                }
            })
            .catch(() => {
                /* Safari ne supporte pas permissions.query("camera") : le
                   getUserMedia ci-dessous produira l'erreur appropriée. */
            });

        // getUserMedia n'existe que sur un contexte sécurisé (HTTPS, ou localhost).
        if (!navigator.mediaDevices?.getUserMedia) {
            setError("La caméra nécessite une connexion sécurisée (HTTPS).");
            setStarting(false);
            return;
        }

        navigator.mediaDevices
            .getUserMedia({
                video: { facingMode: "user", width: { ideal: 1280 }, height: { ideal: 1280 } },
                audio: false,
            })
            .then((stream) => {
                if (cancelled) {
                    stream.getTracks().forEach((track) => track.stop());
                    return;
                }
                streamRef.current = stream;
                if (videoRef.current) {
                    videoRef.current.srcObject = stream;
                }
            })
            .catch((e) => {
                if (!cancelled) {
                    setError(
                        e?.name === "NotAllowedError"
                            ? "Accès à la caméra refusé. Vérifiez l'autorisation caméra de ce site dans les réglages du navigateur (icône à gauche de la barre d'adresse), puis rechargez la page."
                            : "Caméra indisponible sur cet appareil.",
                    );
                }
            })
            .finally(() => {
                if (!cancelled) setStarting(false);
            });

        return () => {
            cancelled = true;
            stopStream();
        };
    }, [open]);

    const capture = () => {
        const video = videoRef.current;
        if (!video || !video.videoWidth) return;

        // Carré centré : les avatars sont ronds partout dans l'app.
        const side = Math.min(video.videoWidth, video.videoHeight);
        const canvas = document.createElement("canvas");
        canvas.width = 640;
        canvas.height = 640;
        const ctx = canvas.getContext("2d");
        ctx.drawImage(
            video,
            (video.videoWidth - side) / 2,
            (video.videoHeight - side) / 2,
            side,
            side,
            0,
            0,
            640,
            640,
        );

        canvas.toBlob(
            (blob) => {
                if (!blob) return;
                onCapture(new File([blob], `camera-${Date.now()}.jpg`, { type: "image/jpeg" }));
                onClose();
            },
            "image/jpeg",
            0.9,
        );
    };

    if (!open) return null;

    return (
        <div
            className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4"
            role="dialog"
            aria-modal="true"
            aria-label="Prendre une photo"
        >
            <div className="bg-white rounded-xl shadow-xl w-full max-w-md overflow-hidden">
                <header className="flex items-center justify-between px-4 py-3 border-b border-gray-100">
                    <h2 className="text-sm font-semibold text-gray-800 flex items-center gap-2">
                        <Camera className="w-4 h-4 text-lamaSky" aria-hidden="true" />
                        Prendre une photo
                    </h2>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Fermer"
                        className="text-gray-400 hover:text-gray-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-lamaSky rounded"
                    >
                        <X className="w-5 h-5" />
                    </button>
                </header>

                <div className="relative aspect-square bg-black">
                    <video
                        ref={videoRef}
                        autoPlay
                        playsInline
                        muted
                        className="w-full h-full object-cover scale-x-[-1]"
                    />
                    {error && (
                        <p className="absolute inset-0 flex items-center justify-center text-center text-sm text-white/90 px-6">
                            {error}
                        </p>
                    )}
                    {!error && !starting && (
                        <div className="absolute inset-10 border-2 border-dashed border-white/50 rounded-full pointer-events-none" />
                    )}
                </div>

                <footer className="flex items-center justify-center gap-3 px-4 py-3">
                    <button
                        type="button"
                        onClick={capture}
                        disabled={!!error || starting}
                        className="inline-flex items-center gap-2 px-5 py-2 rounded-md bg-lamaSky text-white text-sm font-medium hover:bg-lamaSky/90 disabled:bg-gray-300 disabled:cursor-not-allowed transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-lamaSky"
                    >
                        <Camera className="w-4 h-4" aria-hidden="true" />
                        Capturer
                    </button>
                </footer>
            </div>
        </div>
    );
}
