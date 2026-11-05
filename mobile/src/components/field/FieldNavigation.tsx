import { createContext, useContext, type ReactNode } from 'react';
import type { AuthSession } from '../../domain/estiba';

type Navigation = { auth?: AuthSession | null; onChangeModule?: () => void };
const Context = createContext<Navigation>({});

export function FieldNavigationProvider({ children, ...navigation }: Navigation & { children: ReactNode }) {
  return <Context.Provider value={navigation}>{children}</Context.Provider>;
}

export function useFieldNavigation() { return useContext(Context); }
