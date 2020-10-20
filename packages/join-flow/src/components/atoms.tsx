import React, { cloneElement, FC, ReactElement } from "react";
import { Form } from "react-bootstrap";
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
  className?: string;
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
      const currentValue = form?.watch(name);

      return (
        <div
          onClick={() => onChange(value)}
          role="radio"
          aria-checked={currentValue === value}
          className={"radio-panel " + className}
        >
          <div className="radio-panel-label">
            {label}
            <span className="float-right">{valueText}</span>
          </div>
          {description}
          {valueMeta && <span className="float-right">{valueMeta}</span>}
        </div>
      );
    }}
  />
);

interface FormItemProps {
  name: string;
  label?: string;
  form: UseFormMethods<any>;
  children: ReactElement;
  required?: Boolean;
}

export const FormItem: FC<FormItemProps> = ({
  name,
  label,
  form,
  children,
  required
}) => {
  const error = form.errors[name]?.message;
  if (error) {
    console.log(error);
  }

  const isInvalid = !!error;
  const isValid = form.formState.isSubmitted && !error;

  return (
    <Form.Group>
      {label && (
        <Form.Label htmlFor={name + "-field"}>
          {label}{" "}
          {required && (
            <>
              <span aria-hidden="true">*</span>{" "}
              <div className="sr-only">required</div>
            </>
          )}
        </Form.Label>
      )}
      {cloneElement(children, {
        name,
        ref: form.register,
        id: name + "-field",
        isInvalid,
        isValid,
        required
      })}
      {isInvalid && (
        <Form.Control.Feedback type="invalid">{error}</Form.Control.Feedback>
      )}
    </Form.Group>
  );
};

interface DetailPanelProps {
  label: string;
  action: Partial<PageState>;
}

export const DetailsCard: FC = (props) => (
  <div className="d-table bg-white w-100 px-2" {...props} />
);

export const DetailPanel: FC<DetailPanelProps> = ({
  label,
  children,
  action
}) => {
  const router = useCurrentRouter();
  const onRequestChange = () => {
    router.setState({ ...router.state, ...action });
  };

  return (
    <div className="d-table-row text-xs summary-row">
      <div className="d-table-cell p-2 text-secondary text-nowrap">
        {label.replace(/ /g, " ")}
      </div>
      <div className="d-table-cell p-2 w-100">{children}</div>
      <div className="d-table-cell p-2">
        <button
          className="p-0 btn text-secondary btn-link"
          onClick={onRequestChange}
        >
          Change
        </button>
      </div>
    </div>
  );
};
