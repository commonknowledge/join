import React, { cloneElement, FC, ReactElement } from "react";
import { Form, Button } from "react-bootstrap";
import { Controller, UseFormMethods } from "react-hook-form";
import { PageState, useCurrentRouter } from "../services/router.service";

interface ContinueButtonProps {
  text?: string;
  onClick?(event: React.MouseEvent<HTMLButtonElement>): void;
}

const ChevronSVG = (
  <svg
    width="1em"
    height="1em"
    viewBox="0 0 16 16"
    className="bi bi-chevron-right"
    fill="currentColor"
    xmlns="http://www.w3.org/2000/svg"
  >
    <path
      fillRule="evenodd"
      d="M4.646 1.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1 0 .708l-6 6a.5.5 0 0 1-.708-.708L10.293 8 4.646 2.354a.5.5 0 0 1 0-.708z"
    />
  </svg>
);

export const ContinueButton: FC<ContinueButtonProps> = ({ text, onClick }) => (
  <Button
    className="form-section-addon d-flex align-items-center text-uppercase font-family-heading"
    type="submit"
    onClick={onClick}
  >
    {text || "Continue"}
    {ChevronSVG}
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
            <div className="radio-panel-label">
              {label}
              <span className="float-right">{priceLabel}</span>
            </div>

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
