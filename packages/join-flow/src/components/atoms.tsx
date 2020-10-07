import React from "react";
import { FC } from "react";
import { Controller, UseFormMethods } from "react-hook-form";

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
