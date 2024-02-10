import React, { cloneElement, FC, ReactElement } from "react";
import { Form, Button } from "react-bootstrap";
import { Controller, UseFormMethods } from "react-hook-form";
import { PageState, useCurrentRouter } from "../services/router.service";

interface ContinueButtonProps {
  text?: string;
  onClick?(event: React.MouseEvent<HTMLButtonElement>): void;
}

export const ContinueButton: FC<ContinueButtonProps> = ({ text, onClick }) => (
  <Button
    className="form-section-addon d-flex align-items-center text-xxs"
    type="submit"
    onClick={onClick}
  >
    {text || "Continue"}
  </Button>
);

interface RadioPanelProps {
  value: string;
  form?: UseFormMethods<any>;
  name: string;
  label: string;
  priceLabel?: string;
  description?: string;
  valueMeta?: string;
  className?: string;
}

export const RadioPanel: FC<RadioPanelProps> = ({
  value,
  valueMeta,
  description,
  priceLabel,
  form,
  name,
  label,
  className = ""
}) => (
  <Controller
    name={name}
    control={form?.control}
    render={({ onChange }) => {
      const currentValue = form?.watch(name);
      const checked = value === currentValue;

      return (
        <Form.Label
          className={
            "d-flex flex-row radio-panel" +
            className +
            (checked ? " selected" : "")
          }
        >
          <Form.Check
            custom
            inline
            id={name + "-" + value}
            type="radio"
            checked={checked}
            className={checked ? "checked" : undefined}
            onChange={() => onChange(value)}
          />
          <div className="flex-grow-1">
            <h3 className="radio-panel-label mb-0">
              {label}
              <span className="float-right">{priceLabel}</span>
            </h3>

            <div className="radio-panel-description">{description}</div>
            {valueMeta && <span className="float-right">{valueMeta}</span>}
          </div>
        </Form.Label>
      );
    }}
  />
);

interface FormItemProps {
  name: string;
  className?: string;
  label?: string;
  form: UseFormMethods<any>;
  children: ReactElement;
  required?: Boolean;
  after?: ReactElement;
}

export const FormItem: FC<FormItemProps> = ({
  name,
  label,
  form,
  className,
  children,
  after,
  required
}) => {
  const error = form.errors[name]?.message;
  if (error) {
    console.log(error);
  }

  const isInvalid = !!error;
  const isValid = form.formState.isSubmitted && !error;

  return (
    <Form.Group className={className}>
      {label && (
        <Form.Label htmlFor={name}>
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
        id: name,
        isInvalid,
        isValid,
        required
      })}
      {isInvalid && (
        <Form.Control.Feedback type="invalid">{error}</Form.Control.Feedback>
      )}
      {after}
    </Form.Group>
  );
};

interface DetailPanelProps {
  label: string;
  action: Partial<PageState>;
}

export const DetailsCard: FC = (props) => (
  <div className="details-card d-table bg-white w-100" {...props} />
);

export const DetailPanel: FC<DetailPanelProps> = ({
  label,
  children,
  action
}) => {
  const router = useCurrentRouter();
  const onRequestChange = (e: React.MouseEvent) => {
    e.preventDefault();
    router.setState({ ...router.state, ...action });
  };

  return (
    <div className="details-panel d-table-row text-xs summary-row">
      <div className="d-table-cell p-2 p-md-3 w-md-25 text-xxs text-nowrap">
        {label.replace(/ /g, " ")}
      </div>
      <div className="d-table-cell p-2 p-md-3 w-100 w-md-50 text-xxs">{children}</div>
      <div className="d-table-cell p-2 p-md-3 w-md-25 text-xxs text-right">
        <a
          onClick={onRequestChange}
          href="#"
        >
          Change
        </a>
      </div>
    </div>
  );
};
