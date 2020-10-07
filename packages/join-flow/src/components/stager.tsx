import React, { ReactElement } from "react"
import { ComponentType } from "react"

interface StagerProps<T = {}> {
  data: T
  fallback: ReactElement
  onStageCompleted: (state: T) => void
  stage: string
  components: {
    [id: string]: StagerComponent
  }
}

interface StagerComponentProps<T = {}> {
  data: T
  onCompleted: (data: T) => void
}

export type StagerComponent<T = {}> = ComponentType<StagerComponentProps<T>>

export type Stager = <T>(props: StagerProps<T>) => ReactElement
export const Stager: Stager = ({ components, stage, fallback, data, onStageCompleted }) => {
  const StageComponent = components[stage]
  if (!StageComponent) {
    return fallback
  }

  return (
    <StageComponent
      data={data}
      onCompleted={change => onStageCompleted({...data, ...change})}
    />
  )
}
