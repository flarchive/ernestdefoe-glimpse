import type PageStructureComponent from 'flarum/forum/components/PageStructure';
import type IndexSidebarComponent from 'flarum/forum/components/IndexSidebar';
import { defaultExport, runtime } from '../../common/runtime';

/** Core components the pages build on, loaded through the registry: they live in core's lazy chunks. */
export let PageStructure: typeof PageStructureComponent;
export let IndexSidebar: typeof IndexSidebarComponent;

export async function loadCore(): Promise<void> {
  if (PageStructure && IndexSidebar) return;
  const registry = runtime()!.reg;
  const [structure, sidebar] = await Promise.all([
    registry.asyncModuleImport('flarum/forum/components/PageStructure'),
    registry.asyncModuleImport('flarum/forum/components/IndexSidebar'),
  ]);
  PageStructure = defaultExport<typeof PageStructureComponent>(structure);
  IndexSidebar = defaultExport<typeof IndexSidebarComponent>(sidebar);
}
