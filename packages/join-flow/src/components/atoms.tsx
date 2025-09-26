import React, { cloneElement, FC, ReactElement } from "react";
import { Form, Button } from "react-bootstrap";
import { Controller, UseFormMethods } from "react-hook-form";
import { PageState, useCurrentRouter } from "../services/router.service";

interface ContinueButtonProps {
  disabled?: boolean;
  text?: string;
  onClick?(event: React.MouseEvent<HTMLButtonElement>): void;
}

export const ContinueButton: FC<ContinueButtonProps> = ({
  disabled,
  text,
  onClick
}) => (
  <Button
    className="form-section-addon d-flex align-items-center text-xxs"
    type="submit"
    onClick={onClick}
    disabled={disabled}
  >
    {text || "Continue"}
  </Button>
);

interface RadioPanelProps {
  value: string;
  form: UseFormMethods<any>;
  name: string;
  label: string;
  allowCustomAmount?: boolean;
  amount?: number;
  currencySymbol?: string;
  frequency?: string;
  description?: string;
  valueMeta?: string;
  className?: string;
}

export const RadioPanel: FC<RadioPanelProps> = ({
  value,
  valueMeta,
  description,
  amount,
  currencySymbol,
  frequency,
  allowCustomAmount,
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
      const customValue = form?.watch("customMembershipAmount");
      const priceLabel = `${currencySymbol}${amount}, ${frequency}`;

      const onChangeClearCustom = () => {
        if (!allowCustomAmount) {
          form?.setValue("customMembershipAmount", "");
        }
        onChange(value);
      };

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
            onChange={() => onChangeClearCustom()}
          />
          <div className="flex-grow-1">
            <h3 className="radio-panel-label mb-0">
              {label}
              {!allowCustomAmount ? (
                <span className="float-right">{priceLabel}</span>
              ) : null}
            </h3>
            {allowCustomAmount ? (
              <div className="radio-panel-custom-amount">
                {currencySymbol}
                <FormItem
                  name="customMembershipAmount"
                  form={form}
                  required={checked}
                >
                  <Form.Control
                    type="number"
                    min={amount || 1}
                    max="1000"
                    value={customValue || amount || 1}
                    onChange={() => onChange(value)}
                  />
                </FormItem>
                {frequency}
              </div>
            ) : null}

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
  children: ReactElement | ReactElement[];
  required?: Boolean;
  after?: ReactElement;
  style?: React.CSSProperties;
}

export const FormItem: FC<FormItemProps> = ({
  name,
  label,
  form,
  className,
  children,
  after,
  style,
  required
}) => {
  const childArr: ReactElement[] = (
    Array.isArray(children) ? children : [children]
  ) as ReactElement[];
  const error = form.errors[name]?.message;
  if (error) {
    console.log(error);
  }

  const isInvalid = !!error;
  const isValid = form.formState.isSubmitted && !error;

  return (
    <Form.Group className={className} style={style}>
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
      {childArr.map((child, i) =>
        cloneElement(child, {
          name,
          ref: form.register,
          id: child.props.id || name,
          isInvalid,
          isValid,
          key: i,
          required
        })
      )}
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
      <div className="d-table-cell p-2 p-md-3 w-100 w-md-50 text-xxs">
        {children}
      </div>
      <div className="d-table-cell p-2 p-md-3 w-md-25 text-xxs text-right">
        <a onClick={onRequestChange} href="#">
          Change
        </a>
      </div>
    </div>
  );
};
