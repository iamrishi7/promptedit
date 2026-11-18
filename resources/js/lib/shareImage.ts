import { toast } from 'vue-sonner';

/**
 * Share a generated image: the system share sheet where there is one (phones), else copy the link.
 * A stored image has a permanent signed link on this site; before it's stored, the link is
 * Pixelforge's, which stops working 24 hours after the image was made.
 */
export async function shareImage(
    image: { url: string; stored: boolean },
    title: string,
): Promise<void> {
    const url = new URL(image.url, window.location.origin).href;

    if (typeof navigator.share === 'function') {
        try {
            await navigator.share({ title, url });

            return;
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                return; // The user closed the share sheet.
            }
        }
    }

    try {
        await navigator.clipboard.writeText(url);
        toast.success(
            image.stored
                ? 'Image link copied.'
                : 'Image link copied. It works for 24 hours.',
        );
    } catch {
        toast.error(
            "Couldn't copy the link. Open the image and copy its address.",
        );
    }
}
