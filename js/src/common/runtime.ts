/**
 * The parts of the `flarum` global Glimpse reads at runtime: which extensions are enabled, and modules
 * of optional extensions (never a build-time import, so the bundle is the same whether they exist or not).
 */
export interface FlarumRuntime {
  extensions: Record<string, unknown>;
  reg: {
    get(extension: string, path: string): unknown;
    asyncModuleImport(path: string): Promise<unknown>;
  };
}

export const runtime = (): FlarumRuntime | undefined => (globalThis as unknown as { flarum?: FlarumRuntime }).flarum;

export const has = (extension: string): boolean => extension in (runtime()?.extensions ?? {});

/** A module's default export, from a lazily loaded core module or another extension's module. */
export function defaultExport<T>(mod: unknown): T {
  const record = mod as { default?: T } | undefined;
  return (record?.default ?? mod) as T;
}
