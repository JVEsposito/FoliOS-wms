type CameraDisplayIdentity = {
  codigo?: string | null;
  nombre?: string | null;
};

type CameraBandLayout = {
  sentido_numeracion_bandas?: 'izquierda_a_derecha' | 'derecha_a_izquierda';
};

export function cameraDisplayName(camera?: CameraDisplayIdentity | null): string {
  const name = camera?.nombre?.trim();

  return name || camera?.codigo?.trim() || 'Cámara sin nombre';
}

export function orderBandsForCamera(camera: CameraBandLayout, bands: number[]): number[] {
  const ordered = [...bands].sort((left, right) => left - right);

  return camera.sentido_numeracion_bandas === 'derecha_a_izquierda'
    ? ordered.reverse()
    : ordered;
}

export function bandNumberingLabel(camera: CameraBandLayout): string {
  return camera.sentido_numeracion_bandas === 'derecha_a_izquierda'
    ? 'B01 a la derecha'
    : 'B01 a la izquierda';
}
