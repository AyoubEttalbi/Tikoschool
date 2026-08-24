import { useState } from "react";
import { X } from "lucide-react";
import { Dialog, DialogContent, DialogClose, DialogTitle } from "@/Components/ui/dialog";

/**
 * Avatar with click-to-zoom quick view. Renders nothing itself when there is no
 * real image (placeholders are not worth a lightbox). Closes via the round X
 * button, ESC, or clicking the backdrop.
 *
 * The shared DialogContent hardcodes its own close button styled for LIGHT
 * dialogs (accent square + muted icon — invisible/ugly on this black overlay),
 * so it is hidden here via [&>button]:hidden and replaced by a clean circular
 * white X below.
 */
export default function ProfileImageLightbox({ src, alt = "", className = "", enabled }) {
    const [open, setOpen] = useState(false);

    if (!src) {
        return null;
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <img
                src={src}
                alt={alt}
                width={144}
                height={144}
                onClick={() => enabled && setOpen(true)}
                className={`${className} ${enabled ? "cursor-zoom-in hover:opacity-90 transition-opacity" : ""}`}
            />
            <DialogContent className="max-w-3xl p-2 sm:rounded-lg bg-black/90 border-none [&>button]:hidden">
                <DialogTitle className="sr-only">{alt}</DialogTitle>
                <div className="absolute right-3 top-3 z-10">
                    <DialogClose
                        className="flex h-9 w-9 items-center justify-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/25 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
                        aria-label="Fermer"
                    >
                        <X className="h-5 w-5" strokeWidth={2.5} />
                    </DialogClose>
                </div>
                <img
                    src={src}
                    alt={alt}
                    className="max-h-[85vh] w-auto mx-auto rounded-md object-contain"
                />
            </DialogContent>
        </Dialog>
    );
}
