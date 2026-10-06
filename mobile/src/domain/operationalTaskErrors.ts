import type { CameraPlan } from './estiba';

export function cameraSessionConflictMessage(
  plan: Pick<CameraPlan, 'nombre' | 'acceso'>,
  role: 'origen' | 'destino',
): string {
  const session = plan.acceso.sesion;
  const operator = session?.usuario.nombre;
  const device = session?.dispositivo.nombre;
  const owner = operator ? ` por ${operator}${device ? ` desde ${device}` : ''}` : ' por otra sesión';

  return `La cámara de ${role} ${plan.nombre} está en uso${owner}.\n\n`
    + 'Pide al operador que termine y cierre su sesión de estiba. Luego vuelve a intentar la maniobra. '
    + 'Si ya no está trabajando allí, solicita a un supervisor que revise la sesión abierta.';
}
