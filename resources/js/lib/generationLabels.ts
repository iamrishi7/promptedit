/**
 * Display helpers for Pixelforge's sizes and qualities (the only options the API has).
 */
export const SIZE_INFO: Record<
    string,
    { ratio: string; label: string; aspect: string }
> = {
    '1024x1024': { ratio: '1:1', label: 'Square', aspect: '1 / 1' },
    '1536x1024': { ratio: '3:2', label: 'Landscape', aspect: '3 / 2' },
    '1024x1536': { ratio: '2:3', label: 'Portrait', aspect: '2 / 3' },
};

export const ratioOf = (size: string): string => SIZE_INFO[size]?.ratio ?? size;

export const aspectOf = (size: string): string =>
    SIZE_INFO[size]?.aspect ?? '1 / 1';

export const resolutionOf = (size: string): string => size.replace('x', ' × ');

export const QUALITY_INFO: Record<string, { name: string; desc: string }> = {
    low: { name: 'Low', desc: 'Fastest, cheapest' },
    medium: { name: 'Medium', desc: 'Balanced detail' },
    high: { name: 'High', desc: 'Most detail, costs most' },
};

export const qualityName = (quality: string): string =>
    QUALITY_INFO[quality]?.name ?? quality;

/** A short title for a generation: its prompt's first clause, cut at a whole character. */
export function titleOf(prompt: string, max = 48): string {
    const firstClause = prompt.split(/[,.;\n]/)[0]?.trim() || prompt.trim();
    const characters = Array.from(firstClause);

    return characters.length > max
        ? characters
              .slice(0, max - 1)
              .join('')
              .trimEnd() + '…'
        : firstClause;
}
