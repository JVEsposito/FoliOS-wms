import { ApiError } from './apiError';

// Los códigos son extensibles: el catálogo del servidor es la única lista.
export type ContainerCode = string;
export type ContainerOption = { codigo: ContainerCode; nombre: string; orden: number; contiene_fruta: boolean };
const cache = new Map<string, Promise<ContainerOption[]>>();
let labels = new Map<string, string>();

export function containerLabel(code: string): string | undefined { return labels.get(code); }

export function getContainerCatalog(baseUrl: string, token: string): Promise<ContainerOption[]> {
  const key = `${baseUrl}|${token}`;
  if (!cache.has(key)) {
    const pending = fetch(`${baseUrl}/api/envases/catalogo`, { headers: { Accept: 'application/json', Authorization: `Bearer ${token}` } })
      .then(async (response) => {
        const data = await response.json();
        if (!response.ok) throw new ApiError(data.message ?? 'No fue posible cargar los envases.', response.status, data);
        const result = data.data as ContainerOption[];
        labels = new Map(result.map((item) => [item.codigo, item.nombre]));
        return result;
      }).catch((error) => { cache.delete(key); throw error; });
    cache.set(key, pending);
  }
  return cache.get(key)!;
}
