import { defineCollection, z } from 'astro:content';
import { glob } from 'astro/loaders';

const blog = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/blog' }),
  schema: z.object({
    title: z.string(),
    description: z.string().optional(),
    /** Titulek pro vyhledávače (převzatý ze SEOPress) */
    seoTitle: z.string().optional(),
    date: z.coerce.date(),
    /** Kořenová cesta obrázku (/wp-content/uploads/…) */
    image: z.string().optional(),
    /** Původní slug z WordPressu, pokud se liší (kvůli přesměrování) */
    wpSlug: z.string().optional(),
    /** Sezónní okna pro „Brunch tipy“ na HP – viz src/lib/sezona.ts */
    sezona: z.array(z.object({ od: z.string(), do: z.string() })).optional(),
    /** Roční období, kdy se článek hodí víc (bonus na HP) */
    obdobi: z.array(z.enum(['jaro', 'leto', 'podzim', 'zima'])).optional(),
    /** false = nezobrazovat v „Brunch tipech“ na HP (např. SEO rozcestníky) */
    naHp: z.boolean().optional(),
    /** Recept: vykreslí se jako karta na místě značky <!-- recept --> v textu a jako schema.org/Recipe */
    recept: z
      .object({
        nazev: z.string(),
        porce: z.string(),
        priprava: z.number(),
        vareni: z.number().default(0),
        kategorie: z.string(),
        suroviny: z.array(z.string()),
        postup: z.array(z.string()),
        klicova: z.array(z.string()).optional(),
      })
      .optional(),
  }),
});

// Anglické články (/en/<slug>/)
const blogEn = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/blog-en' }),
  schema: z.object({
    title: z.string(),
    description: z.string(),
    seoTitle: z.string().optional(),
    date: z.coerce.date(),
    image: z.string().optional(),
    /** Česká verze článku (cesta) pro hreflang a přepínač jazyka */
    cs: z.string().optional(),
  }),
});

export const collections = { blog, blogEn };
