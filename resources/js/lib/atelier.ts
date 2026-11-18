/**
 * Showcase content for the marketing pages. The images are static art from the design file; the
 * user's own generations always come from the server. The product name is APP_NAME (useAppName).
 */

const img = (name: string): string => `/images/showcase/${name}.jpg`;

export const IMG = {
    hero: img('ember-valley'),
    brass: img('liquid-brass'),
    ringed: img('ringed-giant'),
    rain: img('rain-district'),
    glacier: img('glacier-ridge'),
    silk: img('molten-silk'),
    bloom: img('bloom'),
    forest: img('cloud-forest'),
    harbor: img('harbor-lights'),
    moons: img('twin-moons'),
    marble: img('marble-current'),
    orchid: img('night-orchid'),
    mesa: img('red-mesa'),
} as const;

export type ShowcaseItem = {
    src: string;
    title: string;
    style: string;
    alt: string;
    /** Opening a showcase image starts the studio with a prompt in its spirit. */
    prompt: string;
};

export const SHOWCASE: ShowcaseItem[] = [
    {
        src: IMG.ringed,
        title: 'Ringed giant',
        style: 'Cinematic',
        alt: 'A banded gas giant with glowing rings in deep space',
        prompt: 'A banded gas giant with glowing rings rising over a calm dark sea, two tiny moons, deep space, cinematic lighting',
    },
    {
        src: IMG.brass,
        title: 'Liquid brass study',
        style: 'Abstract',
        alt: 'Swirling teal and gold marbled fluid',
        prompt: 'Swirling teal and liquid gold marbled fluid, macro, glossy, abstract texture',
    },
    {
        src: IMG.forest,
        title: 'Cloud forest',
        style: 'Photoreal',
        alt: 'Misty green ridges under a pale sun',
        prompt: 'Misty green forest ridges under a pale sun, layered fog, wide landscape photograph',
    },
    {
        src: IMG.harbor,
        title: 'Harbor lights',
        style: 'Cinematic',
        alt: 'Amber-lit city skyline in the rain',
        prompt: 'Amber-lit harbor city skyline at night in light rain, reflections on wet streets, cinematic',
    },
    {
        src: IMG.bloom,
        title: 'Bloom no. 7',
        style: 'Illustration',
        alt: 'Translucent coral flower petals on cream',
        prompt: 'Translucent coral paper flower petals on a cream background, soft light, editorial illustration',
    },
    {
        src: IMG.silk,
        title: 'Molten silk',
        style: 'Abstract',
        alt: 'Magenta and orange flowing silk texture',
        prompt: 'Magenta and orange flowing silk, folds catching light, abstract macro texture',
    },
    {
        src: IMG.glacier,
        title: 'Glacier ridge, blue hour',
        style: 'Photoreal',
        alt: 'Blue mountain ridges fading into haze',
        prompt: 'Blue glacier mountain ridges fading into haze at blue hour, quiet and vast, landscape photograph',
    },
    {
        src: IMG.orchid,
        title: 'Night orchid',
        style: 'Illustration',
        alt: 'Violet layered petals on a dark ground',
        prompt: 'Violet layered orchid petals on a dark ground, painterly illustration',
    },
    {
        src: IMG.moons,
        title: 'Twin moons',
        style: '3D Render',
        alt: 'Teal banded planet with two small moons',
        prompt: 'Teal banded planet with two small moons, soft studio lighting, 3D render',
    },
    {
        src: IMG.mesa,
        title: 'Red mesa',
        style: 'Photoreal',
        alt: 'Desert mesas at sunrise in rust tones',
        prompt: 'Desert mesas at sunrise in rust tones, long shadows, landscape photograph',
    },
    {
        src: IMG.rain,
        title: 'Rain district, 2 a.m.',
        style: 'Cinematic',
        alt: 'Neon skyscrapers in heavy rain',
        prompt: 'Rain-soaked downtown at 2 a.m., skyscrapers with magenta and cyan windows, light fog, long exposure streaks, cinematic wide shot',
    },
    {
        src: IMG.marble,
        title: 'Marble current',
        style: 'Abstract',
        alt: 'Green and cream marbled stone pattern',
        prompt: 'Green and cream marbled stone pattern, flowing veins, abstract texture',
    },
];

export const showcaseByTitle = (title: string): ShowcaseItem =>
    SHOWCASE.find((item) => item.title === title) ?? SHOWCASE[0];
