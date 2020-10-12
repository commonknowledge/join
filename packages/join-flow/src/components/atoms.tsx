import React from "react";
import { FC } from "react";
import { Card } from "react-bootstrap";
import { Controller, UseFormMethods } from "react-hook-form";
import { PageState, useCurrentRouter } from "../services/router.service";

interface RadioPanelProps {
  value: string;
  form?: UseFormMethods<any>;
  name: string;
  label: string;
  valueText?: string;
  description?: string;
  valueMeta?: string;
  className?: string
}

export const RadioPanel: FC<RadioPanelProps> = ({
  value,
  valueMeta,
  description,
  valueText,
  form,
  name,
  label,
  className
}) => (
  <Controller
    name={name}
    control={form?.control}
    render={({ onChange }) => {
      const currentValue = form?.watch(name)

      return (
        <div onClick={() => onChange(value)} role="radio" aria-checked={currentValue === value} className={"radio-panel " + className}>
          <div className="radio-panel-label">
            {label}
            <span className="float-right">{valueText}</span>
          </div>
          {description}
          {valueMeta && <span className="float-right">{valueMeta}</span>}
        </div>
      )
    }}
  />
);

interface DetailPanelProps {
  label: string
  action: Partial<PageState>
}

export const DetailsCard: FC = (props) => (
  <div className="d-table bg-white w-100 px-2" {...props}/>
)

export const DetailPanel: FC<DetailPanelProps> = ({ label, children, action }) => {
  const router = useCurrentRouter()
  const onRequestChange = () => {
    router.setState({ ...router.state, ...action })
  }
  
  return (
    <div className="d-table-row text-xs summary-row">
      <div className="d-table-cell p-2 text-secondary text-nowrap">
        {label.replace(/ /g, ' ')}
      </div>
      <div className="d-table-cell p-2 w-100">
        {children}
      </div>
      <div className="d-table-cell p-2">
        <button className="p-0 btn text-secondary btn-link" onClick={onRequestChange}>
          Change
        </button>
      </div>
    </div>
  )
}
